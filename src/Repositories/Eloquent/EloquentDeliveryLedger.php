<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use TelescopeMonitor\Alerts\Envelope;
use TelescopeMonitor\Alerts\EventCatalog;
use TelescopeMonitor\Alerts\Outcome;
use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Integrations\ConnectionEvents;
use TelescopeMonitor\Integrations\Credentials;
use TelescopeMonitor\Integrations\GitHubIssues;
use TelescopeMonitor\Integrations\GitHubPayload;
use TelescopeMonitor\Integrations\WebhookPayload;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\AlertRule;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Delivery;
use TelescopeMonitor\Models\DeliveryAttempt;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\LinkedIssue;
use TelescopeMonitor\Models\NotificationGate;
use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Operations\RetentionSweep;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\SettingsService;
use TelescopeMonitor\Storage\Database;

final class EloquentDeliveryLedger implements DeliveryLedger
{
    public function __construct(private InstallationRepository $installations, private EloquentRuleMatcher $matcher, private Envelope $envelopes, private SettingsService $settings) {}

    public function status(): array
    {
        $i = $this->installations->current()->id;

        return [
            'pending' => Delivery::query()->where('installation_id', $i)->whereIn('status', ['pending', 'processing', 'retrying'])->count(),
            'failed' => Delivery::query()->where('installation_id', $i)->where('status', 'failed')->count(),
            'needs_review' => Delivery::query()->where('installation_id', $i)->where('status', 'needs_review')->count(),
            'oldest_pending_at' => Delivery::query()->where('installation_id', $i)->whereIn('status', ['pending', 'retrying'])->min('eligible_at'),
            'last_accepted_at' => Delivery::query()->where('installation_id', $i)->max('sent_at'),
        ];
    }

    public function routing(int $id): ?array
    {
        $d = Delivery::query()->where('installation_id', $this->installations->current()->id)->find($id);

        return $d ? ['actor_required' => $d->actor_required, 'actor_key' => $d->actor_key, 'action' => $d->action] : null;
    }

    public function support(Delivery $d, ?Issue $issue): array
    {
        if (str_starts_with($d->action, 'report_')) {
            return ['valid' => app(\TelescopeMonitor\Reports\ReportScheduler::class)->supports($d), 'cooldown' => 0];
        }
        $destination = Connection::query()->find($d->connection_id);
        if ($d->action !== 'connection_test' && $destination && ! ConnectionEvents::allows($destination, AlertEvent::query()->findOrFail($d->event_id)->event_type)) {
            return ['valid' => false, 'cooldown' => 0];
        }
        if (str_starts_with($d->action, 'github_')) {
            return ['valid' => app(GitHubIssues::class)->support($d, $issue), 'cooldown' => 0];
        }
        if ($d->action === 'connection_test') {
            return ['valid' => true, 'cooldown' => 0];
        }
        if (! $issue || ! $this->matcher->allowed($issue)) {
            return ['valid' => false, 'cooldown' => 0];
        }
        $refs = Database::connection()->table('telescope_monitor_delivery_rules')->where('delivery_id', $d->id)->get();
        $valid = false;
        $cooldown = 0;
        foreach ($refs as $ref) {
            $rule = AlertRule::query()->where('installation_id', $d->installation_id)->find($ref->rule_id);
            $event = AlertEvent::query()->where('installation_id', $d->installation_id)->find($ref->matching_event_id);
            if (! $rule || ! $event || ! Database::connection()->table('telescope_monitor_rule_connections')->where('rule_id', $rule->id)->where('connection_id', $d->connection_id)->exists()) {
                continue;
            }
            if (EventCatalog::canonical($event->event_type) === 'error.threshold_reached' && $rule->row_version !== (int) $ref->rule_version) {
                continue;
            }
            if ($this->matcher->matches($rule, $issue, $event) && $this->matcher->eligible($event, $issue)) {
                $valid = true;
                $cooldown = max($cooldown, $rule->cooldown_seconds);
            }
        }

        return ['valid' => $valid, 'cooldown' => $cooldown];
    }

    public function envelope(Delivery $d): array
    {
        $event = AlertEvent::query()->where('installation_id', $d->installation_id)->findOrFail($d->event_id);
        $json = Crypt::decryptString($event->payload_ciphertext);
        if (! hash_equals($event->payload_digest, hash('sha256', $json))) {
            throw new \RuntimeException('event_integrity');
        }$snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (str_starts_with($d->action, 'report_')) {
            return $this->envelopes->sanitize($snapshot + [
                'delivery_id' => $d->public_id,
                'event_id' => $event->public_id,
                'installation_id' => $this->installations->current()->public_id,
            ]);
        }
        $issue = $d->issue_id ? Issue::query()->where('installation_id', $d->installation_id)->findOrFail($d->issue_id) : null;
        $general = $this->settings->group('general')['values'];
        $url = rtrim($general['dashboard_url'], '/').($issue ? '/errors/'.$issue->public_id : '/setup');
        $occ = null;
        if ($event->occurrence_id) {
            $occ = Occurrence::query()->find($event->occurrence_id);
            if ($occ) {
                $url .= '?occurrence='.$occ->public_id;
            }
        }

        $result = $this->envelopes->sanitize([
            'event_id' => $event->public_id,
            'installation_id' => $this->installations->current()->public_id,
            'issue_id' => $issue?->public_id,
            'staged_key_test' => $d->getAttribute('credential_key_id') !== null,
            'version' => 1,
            'delivery_id' => $d->public_id,
            'event' => EventCatalog::canonical($event->event_type),
            'label' => $d->action === 'connection_test' ? 'Monitor test' : (EventCatalog::LABELS[EventCatalog::canonical($event->event_type)] ?? 'Monitoring activity'),
            'test' => $d->action === 'connection_test',
            'application' => $general['display_name'],
            'environment' => $issue->environment ?? app()->environment(),
            'class' => $snapshot['class'] ?? 'SyntheticMonitorCheck',
            'message' => $snapshot['message'] ?? 'Synthetic delivery verification',
            'file' => $snapshot['file'] ?? '',
            'line' => (int) ($snapshot['line'] ?? 0),
            'count' => (string) ($snapshot['count'] ?? 0),
            'occurred_at' => $event->occurred_at->toISOString(),
            'url' => $url,
        ]);
        $c = Connection::query()->findOrFail($d->connection_id);
        if ($c->provider === 'email') {
            $result['email_context'] = app(\TelescopeMonitor\Alerts\EmailContext::class)->build($issue, $occ, $general, \TelescopeMonitor\Alerts\EventCatalog::occurrence($result['event']));
        }
        if ($c->provider === 'slack') {
            $result['slack_context'] = app(\TelescopeMonitor\Alerts\SlackContext::class)->build($issue, $occ, $general, EventCatalog::occurrence($result['event']));
        }
        if ($c->provider === 'webhook') {
            $result['webhook_body'] = WebhookPayload::bytes($result);
        }
        if ($c->provider === 'github' && ! $result['test']) {
            $link = LinkedIssue::query()->where('create_delivery_id', $d->id)->firstOrFail();
            $result['github_context'] = app(\TelescopeMonitor\Integrations\GitHubContext::class)->build($issue, $occ, $general, true);
            $result = GitHubPayload::render($result, $link->getAttribute('operation_public_id'), null, $c->options['labels'] ?? []);
        }

        return $this->envelopes->sanitize($result);
    }

    private function cancel(Delivery $d, string $reason): void
    {
        $d->status = 'cancelled';
        $d->cancellation_reason = $reason;
        $d->completed_at = CarbonImmutable::now('UTC');
        $d->claim_token = null;
        $d->lease_until = null;
        $d->save();
        if (str_starts_with($d->action, 'github_')) {
            LinkedIssue::query()->where('create_delivery_id', $d->id)->whereIn('state', ['reserved', 'creating'])->update(['state' => 'unavailable', 'row_version' => Database::connection()->raw('row_version + 1')]);
        }
    }

    private function gate(Delivery $d, bool $create = true): ?NotificationGate
    {
        if (! $d->issue_id || $d->action === 'connection_test' || str_starts_with($d->action, 'github_')) {
            return null;
        }
        if (! $create) {
            return NotificationGate::query()->where('installation_id', $d->installation_id)->where('issue_id', $d->issue_id)->where('target_fingerprint', $d->target_fingerprint)->where('event_family', $d->event_family)->lockForUpdate()->first();
        }
        $gate = NotificationGate::query()->createOrFirst([
            'installation_id' => $d->installation_id,
            'issue_id' => $d->issue_id,
            'target_fingerprint' => $d->target_fingerprint,
            'event_family' => $d->event_family,
        ]);

        return NotificationGate::query()->whereKey($gate->id)->lockForUpdate()->firstOrFail();
    }

    public function claim(int $id, ?int $generation, bool $actorAllowed): ?array
    {
        $route = Delivery::query()->where('installation_id', $this->installations->current()->id)->find($id);
        if (! $route) {
            return null;
        }

        return Database::connection()->transaction(function () use ($route, $id, $generation, $actorAllowed) {
            $i = $this->installations->fence();
            if ($i->state !== 'active') {
                return null;
            }
            if ($route->generation !== $i->generation || ($generation !== null && $generation !== $i->generation)) {
                $stale = Delivery::query()->where('installation_id', $i->id)->whereKey($id)->lockForUpdate()->first();
                if ($stale && in_array($stale->status, ['pending', 'retrying', 'processing'], true)) {
                    $this->cancel($stale, 'installation_fenced');
                }

                return null;
            }
            $issue = $route->issue_id ? Issue::query()->where('installation_id', $i->id)->whereKey($route->issue_id)->lockForUpdate()->first() : null;
            $connection = Connection::query()->where('installation_id', $i->id)->whereKey($route->connection_id)->lockForUpdate()->first();
            $gate = $this->gate($route);
            $d = Delivery::query()->where('installation_id', $i->id)->whereKey($id)->lockForUpdate()->first();
            if (! $d || ! in_array($d->status, ['pending', 'retrying', 'processing'], true)) {
                return null;
            }
            $now = CarbonImmutable::now('UTC');
            if ($d->status === 'processing' && $d->lease_until?->isFuture()) {
                return null;
            }
            if ($d->status === 'processing') {
                if (str_starts_with($d->action, 'github_')) {
                    DeliveryAttempt::query()->where('delivery_id', $d->id)->where('claim_token', $d->claim_token)->whereNull('completed_at')->update([
                        'outcome' => 'uncertain',
                        'safe_error_code' => 'worker_lease_expired',
                        'sanitized_error_summary' => 'Creation acceptance is unknown; review before another write.',
                        'completed_at' => $now->format('Y-m-d H:i:s.u'),
                    ]);
                    $d->status = 'needs_review';
                    $d->last_error_code = 'worker_lease_expired';
                    $d->claim_token = null;
                    $d->lease_until = null;
                    $d->save();
                    app(GitHubIssues::class)->uncertain($d);

                    return null;
                }

                DeliveryAttempt::query()->where('delivery_id', $d->id)->where('claim_token', $d->claim_token)->whereNull('completed_at')->update([
                    'outcome' => 'uncertain',
                    'safe_error_code' => 'worker_lease_expired',
                    'sanitized_error_summary' => 'Worker ownership expired; provider acceptance may have occurred.',
                    'completed_at' => $now->format('Y-m-d H:i:s.u'),
                ]);
                if ($gate?->reserved_delivery_id === $d->id) {
                    $gate->reserved_delivery_id = null;
                    $gate->reservation_token = null;
                    $gate->lease_until = null;
                    $gate->save();
                }
            }
            if ($d->generation !== $i->generation) {
                $this->cancel($d, 'installation_fenced');

                return null;
            }
            if (! $actorAllowed) {
                $this->cancel($d, 'initiator_unauthorized');

                return null;
            }
            if (! config('telescope-monitor.alerts.enabled', true)) {
                $this->cancel($d, 'delivery_disabled');

                return null;
            }
            if ($d->expires_at <= $now) {
                $this->cancel($d, 'expired');

                return null;
            }
            if ($d->eligible_at > $now) {
                return null;
            }

            if (! $connection || ! $connection->enabled || $connection->disconnected_at || $connection->target_fingerprint !== $d->target_fingerprint) {
                $this->cancel($d, 'destination_unavailable');

                return null;
            }
            if ($connection->provider === 'slack' && (($connection->transport_lease_until && $connection->transport_lease_until > $now) || ($connection->next_send_at && $connection->next_send_at > $now))) {
                $d->eligible_at = $connection->transport_lease_until?->max($connection->next_send_at ?? $now) ?? $connection->next_send_at;
                $d->status = 'pending';
                $d->save();

                return null;
            }
            $support = $this->support($d, $issue);
            if (! $support['valid']) {
                $this->cancel($d, 'no_current_rule_support');

                return null;
            }
            $event = AlertEvent::query()->where('installation_id', $i->id)->findOrFail($d->event_id);
            if ($gate && $gate->reserved_delivery_id !== null && $gate->reserved_delivery_id !== $d->id && $gate->lease_until?->isFuture()) {
                $d->status = 'pending';
                $d->eligible_at = $gate->lease_until;
                $d->save();

                return null;
            }
            if ($gate?->last_sent_at && $gate->last_sent_at->addSeconds($support['cooldown']) > $now) {
                if (EventCatalog::canonical($event->event_type) === 'error.regressed') {
                    $d->status = 'pending';
                    $d->eligible_at = $gate->last_sent_at->addSeconds($support['cooldown']);
                    $d->save();
                } else {
                    $this->cancel($d, 'cooldown');
                }

                return null;
            }
            if ($d->cycle_attempts >= 5) {
                $d->status = 'failed';
                $d->last_error_code = 'attempts_exhausted';
                $d->completed_at = $now;
                $d->claim_token = null;
                $d->lease_until = null;
                $d->save();

                return null;
            }
            try {
                $target = app(Credentials::class)->target($connection, $d->getAttribute('credential_key_id'));
                if ($d->payload_ciphertext !== null) {
                    $body = Crypt::decryptString($d->payload_ciphertext);
                    if (! hash_equals($d->payload_digest ?? '', hash('sha256', $body))) {
                        throw new \RuntimeException;
                    }$envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    if ($envelope !== $this->envelopes->sanitize($envelope)) {
                        $this->cancel($d, 'export_policy_changed');

                        return null;
                    }
                } else {
                    $envelope = $this->envelope($d);
                    $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $d->payload_ciphertext = Crypt::encryptString($body);
                    $d->payload_digest = hash('sha256', $body);
                    $d->payload_frozen_at = $now;
                    $d->export_policy_revision = $i->config_revision;
                }

            } catch (\Throwable) {
                $d->status = 'failed';
                $d->last_error_code = 'payload_or_target_unreadable';
                $d->completed_at = $now;
                $d->claim_token = null;
                $d->lease_until = null;
                $d->save();

                return null;
            }
            $d->status = 'processing';
            $d->claim_token = (string) Str::uuid();
            $d->lease_until = $now->addSeconds(120);
            $d->attempt_count_total++;
            $d->cycle_attempts++;
            $d->last_error_code = null;
            $d->save();
            if ($gate) {
                $gate->reserved_delivery_id = $d->id;
                $gate->reservation_token = $d->claim_token;
                $gate->lease_until = $d->lease_until;
                $gate->save();
            }
            DeliveryAttempt::query()->create([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i->id,
                'delivery_id' => $d->id,
                'sequence_number' => $d->attempt_count_total,
                'retry_cycle' => $d->retry_cycle,
                'claim_token' => $d->claim_token,
                'started_at' => $now,
                'outcome' => 'processing',
                'used_secret_key_id' => $target['signing_key_id'] ?? $target['key_id'],
            ]);

            if ($connection->provider === 'slack') {
                $connection->transport_claim_token = $d->claim_token;
                $connection->transport_lease_until = $d->lease_until;
                $connection->save();
            }
            if (str_starts_with($d->action, 'github_')) {
                app(GitHubIssues::class)->claimed($d);
            }

            return ['token' => $d->claim_token, 'envelope' => $envelope, 'target' => $target, 'generation' => $d->generation];
        }, 5);
    }

    public function finish(int $id, string $token, Outcome $outcome): bool
    {
        $route = Delivery::query()->find($id);
        if (! $route) {
            return false;
        }

        return Database::connection()->transaction(function () use ($route, $id, $token, $outcome) {
            $i = $this->installations->fence();
            if ($route->generation !== $i->generation) {
                return false;
            }
            if ($route->issue_id) {
                Issue::query()->whereKey($route->issue_id)->lockForUpdate()->first();
            }
            $connection = Connection::query()->where('installation_id', $i->id)->whereKey($route->connection_id)->lockForUpdate()->first();
            $gate = $this->gate($route, false);
            $d = Delivery::query()->where('installation_id', $i->id)->whereKey($id)->lockForUpdate()->first();
            if (! $d || $d->generation !== $i->generation || $d->claim_token !== $token || $d->status !== 'processing') {
                return false;
            }
            $now = CarbonImmutable::now('UTC');
            $needsReview = str_starts_with($d->action, 'github_') && $outcome->kind === 'uncertain';
            $retry = ! $needsReview && $outcome->kind !== 'accepted' && $outcome->kind !== 'permanent' && $d->cycle_attempts < 5;
            $eligible = $now->addSeconds([60, 300, 900, 3600][min(3, max(0, $d->cycle_attempts - 1))]);
            if ($outcome->retryAfter && $outcome->retryAfter > $eligible) {
                $eligible = $outcome->retryAfter;
            }
            DeliveryAttempt::query()->where('delivery_id', $d->id)->where('claim_token', $token)->whereNull('completed_at')->update([
                'completed_at' => $now->format('Y-m-d H:i:s.u'),
                'outcome' => $outcome->kind,
                'provider_status' => $outcome->status,
                'safe_error_code' => $outcome->code,
                'sanitized_error_summary' => match ($outcome->kind) {
                    'accepted' => 'The host transport accepted the message.','permanent' => 'The configured transport rejected the operation.', 'uncertain' => 'Transport acceptance is unknown; recovery may duplicate this message.',default => 'The configured transport was temporarily unavailable.'
                },
                'retry_after_at' => $retry ? $eligible->format('Y-m-d H:i:s.u') : null,
            ]);
            $d->status = $needsReview ? 'needs_review' : ($outcome->kind === 'accepted' ? 'sent' : ($retry ? 'retrying' : 'failed'));
            $d->sent_at = $outcome->kind === 'accepted' ? $now : null;
            $d->completed_at = $retry ? null : $now;
            $d->eligible_at = $eligible;
            $d->last_error_code = $outcome->kind === 'accepted' ? null : $outcome->code;
            $d->claim_token = null;
            $d->lease_until = null;
            $d->save();
            if ($gate && $gate->reservation_token === $token) {
                $gate->reserved_delivery_id = null;
                $gate->reservation_token = null;
                $gate->lease_until = null;
                if ($outcome->kind === 'accepted') {
                    $gate->last_sent_at = $now;
                    $gate->last_event_public_id = AlertEvent::query()->whereKey($d->event_id)->value('public_id');
                }$gate->save();
            }
            if (str_starts_with($d->action, 'github_')) {
                app(GitHubIssues::class)->completed($d, $outcome);
            }
            if ($connection && $connection->provider === 'slack' && $connection->transport_claim_token === $token) {
                $connection->transport_claim_token = null;
                $connection->transport_lease_until = null;
                $connection->next_send_at = $outcome->retryAfter?->max($now->addSecond()) ?? $now->addSecond();
                $connection->save();
            }
            if ($connection && $connection->provider === 'github' && $d->action === 'connection_test' && $outcome->kind === 'accepted') {
                $options = $connection->options;
                $options['known_labels'] = $outcome->reference['labels'] ?? [];
                $connection->options = $options;
                $connection->save();
            }
            if ($connection && $outcome->kind === 'accepted') {
                $options = $connection->options;
                $options['last_accepted_at'] = $now->toISOString();
                $connection->options = $options;
                $connection->save();
            }
            if ($d->action === 'connection_test') {
                Connection::query()->whereKey($d->connection_id)->update([
                    'last_test_at' => $now->format('Y-m-d H:i:s.u'),
                    'last_test_outcome' => $outcome->kind === 'accepted' ? 'accepted' : ($retry ? 'retrying' : 'failed'),
                ]);
            }

            return true;
        }, 5);
    }

    public function due(int $limit): array
    {
        return Delivery::query()->where('installation_id', $this->installations->current()->id)->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', ['pending', 'retrying'])->where('eligible_at', '<=', now('UTC')->format('Y-m-d H:i:s.u')))->orWhere(fn ($q) => $q->where('status', 'processing')->where('lease_until', '<=', now('UTC')->format('Y-m-d H:i:s.u'))))->orderBy('id')->limit($limit)->get(['id', 'generation'])->map(fn ($d) => ['id' => $d->id, 'generation' => $d->generation])->all();
    }

    public function prune(int $limit): array
    {
        if (app(RetentionPolicy::class)->ready()) {
            return app(RetentionSweep::class)->deliveries($limit);
        }
        $days = app(CapturePolicy::class)->read()['retention']['delivery_days'] ?? 30;
        Delivery::query()->whereIn('status', ['pending', 'retrying'])->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->update(['status' => 'cancelled', 'cancellation_reason' => 'expired', 'completed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        if (app(GitHubIssues::class)->ready()) {
            $expired = fn () => Delivery::query()->where(fn ($q) => $q->where('status', 'cancelled')->orWhere(fn ($q) => $q->where('status', 'failed')->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))))->select('id');
            $links = LinkedIssue::query()->where('installation_id', $this->installations->current()->id)->where('state', 'reserved')->whereIn('create_delivery_id', $expired())->orderBy('id')->limit($limit)->pluck('id');
            LinkedIssue::query()->whereKey($links)->where('state', 'reserved')->whereIn('create_delivery_id', $expired())->update(['state' => 'unavailable', 'row_version' => Database::connection()->raw('row_version + 1')]);
        }
        $ids = Delivery::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('telescope_monitor_deliveries as children')->whereColumn('children.parent_delivery_id', 'telescope_monitor_deliveries.id'))->whereIn('status', ['sent', 'cancelled', 'failed'])->where('completed_at', '<', now('UTC')->subDays($days)->format('Y-m-d H:i:s.u'))->orderBy('id')->limit($limit)->pluck('id');
        if (app(GitHubIssues::class)->ready()) {
            $ids = $ids->diff(LinkedIssue::query()->whereIn('state', ['reserved', 'creating', 'needs_review'])->whereIn('create_delivery_id', $ids)->pluck('create_delivery_id'));
        }
        $removed = Delivery::query()->whereKey($ids)->delete();
        $eventIds = AlertEvent::query()->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('telescope_monitor_deliveries')->whereColumn('event_id', 'telescope_monitor_alert_events.id'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('telescope_monitor_delivery_rules')->whereColumn('matching_event_id', 'telescope_monitor_alert_events.id'))->orderBy('id')->limit($limit)->pluck('id');
        $events = AlertEvent::query()->whereKey($eventIds)->delete();

        app(Credentials::class)->cleanup();

        return ['deliveries' => $removed, 'events' => $events];
    }
}
