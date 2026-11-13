<?php

namespace TelescopeMonitor\Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Alerts\DeliveryDispatcher;
use TelescopeMonitor\Alerts\Envelope;
use TelescopeMonitor\Alerts\Outcome;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Collaboration\Identity;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Delivery;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\LinkedIssue;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\SettingsService;
use TelescopeMonitor\Storage\Database;

final class GitHubIssues
{
    public function __construct(private InstallationRepository $installations, private Capabilities $caps, private Identity $identity, private SettingsService $settings, private Envelope $envelopes, private DeliveryDispatcher $dispatcher) {}

    public function ready(): bool
    {
        return Database::schema()->hasTable('telescope_monitor_linked_issues');
    }

    public function connection(string $id): Connection
    {
        return Connection::query()->where('installation_id', $this->installations->current()->id)->where('provider', 'github')->where('public_id', $id)->where('enabled', true)->whereNull('disconnected_at')->firstOrFail();
    }

    public function issue(string $id, bool $lock = false): Issue
    {
        $q = Issue::query()->where('installation_id', $this->installations->current()->id)->where('public_id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->firstOrFail();
    }

    private function base(Issue $issue, string $key): array
    {
        $g = $this->settings->group('general')['values'];
        $i = $this->installations->current();

        return $this->envelopes->sanitize([
            'version' => 1,
            'delivery_id' => $key,
            'event_id' => $key,
            'installation_id' => $i->public_id,
            'issue_id' => $issue->public_id,
            'event' => 'github.export',
            'label' => 'GitHub issue export',
            'test' => false,
            'application' => $g['display_name'],
            'environment' => $issue->environment,
            'class' => $issue->exception_class,
            'message' => $issue->message,
            'file' => $issue->file,
            'line' => $issue->line,
            'count' => (string) $issue->lifetime_count,
            'occurred_at' => $issue->last_seen_at->toISOString(),
            'url' => rtrim($g['dashboard_url'], '/').'/errors/'.$issue->public_id,
            'github_context' => app(GitHubContext::class)->build($issue, \TelescopeMonitor\Models\Occurrence::query()->where('issue_id', $issue->id)->orderByDesc('occurred_at')->orderByDesc('id')->first(), $g, true),
        ]);
    }

    public function preview(string $issueId, array $v): array
    {
        abort_unless($this->caps->allows('operate'), 403);
        $issue = $this->issue($issueId);
        $c = $this->connection($v['connection']);
        $labels = $v['labels'] ?? [];
        if (array_diff($labels, $c->options['known_labels'] ?? [])) {
            throw ValidationException::withMessages(['labels' => 'Select labels observed by a read-only repository check.']);
        }sort($labels);
        $e = $this->envelopes->sanitize(GitHubPayload::render($this->base($issue, $v['preview_key']), $v['preview_key'], $v['title'] ?? null, $labels));
        $link = LinkedIssue::query()->where('issue_id', $issue->id)->where('repository_fingerprint', $c->target_fingerprint)->first();
        $digest = $this->identity->digest([
            'export' => $e,
            'target' => $c->target_fingerprint,
            'connection_version' => $c->row_version,
            'issue_version' => $issue->state_version,
            'policy' => $this->installations->current()->config_revision,
            'link_version' => $link->row_version ?? 0,
        ]);

        return [
            'envelope' => $e,
            'title' => $e['github_title'],
            'body' => $e['github_body'],
            'labels' => $labels,
            'repository' => $c->target_public['owner'].'/'.$c->target_public['repo'],
            'digest' => $digest,
            'link_version' => $link->row_version ?? 0,
        ];
    }

    public function reserve(Issue $issue, Connection $c, AlertEvent $event, string $operation, ?string $actor = null, ?array $frozen = null, bool $recreate = false, ?int $version = null): LinkedIssue
    {
        $link = LinkedIssue::query()->where('issue_id', $issue->id)->where('repository_fingerprint', $c->target_fingerprint)->lockForUpdate()->first();
        if ($link && ! in_array($link->state, ['unlinked', 'unavailable'], true) && ! $recreate) {
            return $link;
        }
        if ($link && ($version !== $link->row_version || ($recreate && $link->state !== 'needs_review'))) {
            throw new Conflict('The GitHub reservation changed. Reload before confirming.');
        }
        if ($link && $link->create_delivery_id) {
            Delivery::query()->whereKey($link->create_delivery_id)->whereIn('status', ['pending', 'retrying', 'failed', 'needs_review'])->update([
                'status' => 'cancelled',
                'cancellation_reason' => 'review_replaced',
                'completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
        }
        $i = $this->installations->current();
        $d = Delivery::query()->create([
            'public_id' => $operation,
            'installation_id' => $i->id,
            'generation' => $i->generation,
            'issue_id' => $issue->id,
            'event_id' => $event->id,
            'parent_delivery_id' => $link?->create_delivery_id,
            'connection_id' => $c->id,
            'action' => $actor ? 'github_create' : 'github_auto',
            'dedupe_key' => hash('sha256', 'github:'.$operation),
            'target_fingerprint' => $c->target_fingerprint,
            'actor_required' => $actor !== null,
            'actor_key' => $actor,
            'event_family' => 'github',
            'alert_generation' => $issue->alert_generation,
            'eligible_at' => CarbonImmutable::now('UTC'),
            'expires_at' => CarbonImmutable::now('UTC')->addSeconds(config('telescope-monitor.alerts.pending_seconds', 86400)),
        ]);
        if ($frozen) {
            $body = json_encode($frozen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $d->payload_ciphertext = Crypt::encryptString($body);
            $d->payload_digest = hash('sha256', $body);
            $d->payload_frozen_at = CarbonImmutable::now('UTC');
            $d->export_policy_revision = $i->config_revision;
            $d->save();
        }
        $link ??= new LinkedIssue([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $i->id,
            'issue_id' => $issue->id,
            'repository_fingerprint' => $c->target_fingerprint,
        ]);
        $link->fill([
            'connection_id' => $c->id,
            'repository_owner' => $c->target_public['owner'],
            'repository_name' => $c->target_public['repo'],
            'state' => 'reserved',
            'operation_public_id' => $operation,
            'create_delivery_id' => $d->id,
            'remote_number' => null,
            'remote_url' => null,
            'last_known_remote_state' => null,
            'checked_at' => null,
            'linked_by_actor_key' => $actor,
        ]);
        if ($link->exists) {
            $link->row_version++;
        }$link->save();
        Database::connection()->afterCommit(fn () => $this->dispatcher->dispatch($d->id, $d->generation));

        return $link;
    }

    public function automatic(AlertEvent $event, Issue $issue): void
    {
        if (! $this->ready() || $event->event_type !== 'error.created' || $event->provenance !== 'live' || $issue->is_test) {
            return;
        }
        foreach (Connection::query()->where('installation_id', $issue->installation_id)->where('provider', 'github')->where('enabled', true)->whereNull('disconnected_at')->get() as $c) {
            if (! ($c->options['automatic'] ?? false) || ! in_array($issue->environment, $c->options['environments'] ?? [], true)) {
                continue;
            }
            // A tombstone or a prior reservation is never implicitly recreated by capture.
            if (LinkedIssue::query()->where('issue_id', $issue->id)->where('repository_fingerprint', $c->target_fingerprint)->exists()) {
                continue;
            }
            $this->reserve($issue, $c, $event, (string) Str::uuid());
        }
    }

    public function support(Delivery $d, ?Issue $issue): bool
    {
        if (! $issue || ! $this->ready()) {
            return false;
        }
        $link = LinkedIssue::query()->where('issue_id', $issue->id)->where('create_delivery_id', $d->id)->whereIn('state', ['reserved', 'creating'])->first();
        if (! $link) {
            return false;
        }
        if ($d->action === 'github_auto') {
            $c = Connection::query()->find($d->connection_id);

            return ! $issue->is_test && $issue->status === 'unresolved' && $issue->alert_generation === $d->alert_generation && ($c->options['automatic'] ?? false) && in_array($issue->environment, $c->options['environments'] ?? [], true) && in_array($issue->environment, config('telescope-monitor.alerts.allowed_environments', ['production']), true);
        }

        return true;
    }

    public function claimed(Delivery $d): void
    {
        LinkedIssue::query()->where('create_delivery_id', $d->id)->update(['state' => 'creating', 'row_version' => Database::connection()->raw('row_version + 1')]);
    }

    public function uncertain(Delivery $d): void
    {
        LinkedIssue::query()->where('create_delivery_id', $d->id)->update(['state' => 'needs_review', 'row_version' => Database::connection()->raw('row_version + 1')]);
    }

    public function completed(Delivery $d, Outcome $o): void
    {
        $q = LinkedIssue::query()->where('create_delivery_id', $d->id);
        if ($o->kind === 'accepted' && $o->reference) {
            $q->update([
                'state' => 'linked',
                'row_version' => Database::connection()->raw('row_version + 1'),
                'remote_number' => $o->reference['number'],
                'remote_url' => $o->reference['url'],
                'last_known_remote_state' => $o->reference['state'],
                'checked_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
        } elseif ($o->kind === 'uncertain') {
            $this->uncertain($d);
        } else {
            $q->update(['state' => 'reserved', 'row_version' => Database::connection()->raw('row_version + 1')]);
        }
    }

    public function links(string $issueId): array
    {
        abort_unless($this->caps->allows('view'), 403);
        $issue = $this->issue($issueId);

        return LinkedIssue::query()->where('issue_id', $issue->id)->orderBy('id')->limit(100)->get()->map(fn ($l) => [
            'id' => $l->public_id,
            'connection' => Connection::query()->find($l->connection_id)?->public_id,
            'repository' => $l->repository_owner.'/'.$l->repository_name,
            'state' => $l->state,
            'version' => $l->row_version,
            'number' => $l->remote_number,
            'url' => $l->remote_url,
            'remote_state' => $l->last_known_remote_state,
            'checked_at' => $l->checked_at?->toISOString(),
            'operation' => $l->operation_public_id,
            'delivery' => Delivery::query()->find($l->create_delivery_id)?->public_id,
        ])->all();
    }

    public function confirmed(string $issueId, array $v, bool $recreate, string $actor): array
    {
        $issue = $this->issue($issueId, true);
        $c = $this->connection($v['connection']);
        $prior = LinkedIssue::query()->where('issue_id', $issue->id)->where('repository_fingerprint', $c->target_fingerprint)->lockForUpdate()->first();
        if ($prior && ! $recreate && ! in_array($prior->state, ['unlinked', 'unavailable'], true)) {
            return [
                'id' => $prior->public_id,
                'state' => $prior->state,
                'version' => $prior->row_version,
                'delivery' => Delivery::query()->find($prior->create_delivery_id)?->public_id,
            ];
        }
        $preview = $this->preview($issueId, $v);
        if (! hash_equals($preview['digest'], $v['preview_digest'])) {
            throw new Conflict('The export preview changed. Review the current sanitized bytes before confirming.');
        }
        $issue = $this->issue($issueId, true);
        $c = $this->connection($v['connection']);
        if ($recreate && ! ($v['confirm_no_ticket'] ?? false)) {
            throw new Conflict('Explicitly confirm that no GitHub ticket exists before a new attempt.');
        }
        $json = json_encode([
            'class' => $issue->exception_class,
            'message' => $issue->message,
            'file' => $issue->file,
            'line' => $issue->line,
            'count' => (string) $issue->lifetime_count,
        ], JSON_THROW_ON_ERROR);
        $i = $this->installations->current();
        $event = AlertEvent::query()->create([
            'public_id' => $v['preview_key'],
            'installation_id' => $i->id,
            'generation' => $i->generation,
            'issue_id' => $issue->id,
            'event_type' => 'github.export',
            'event_key' => hash('sha256', 'github:'.$v['preview_key']),
            'action_public_id' => $v['preview_key'],
            'issue_state_version' => $issue->state_version,
            'alert_generation' => $issue->alert_generation,
            'occurred_at' => $issue->last_seen_at,
            'recorded_at' => CarbonImmutable::now('UTC'),
            'provenance' => 'manual',
            'actor_key' => $actor,
            'payload_ciphertext' => Crypt::encryptString($json),
            'payload_digest' => hash('sha256', $json),
            'planning_state' => 'planned',
            'expires_at' => CarbonImmutable::now('UTC')->addDays(30),
        ]);
        $link = $this->reserve($issue, $c, $event, $v['preview_key'], $actor, $preview['envelope'], $recreate, $v['link_version'] ?? null);

        return [
            'id' => $link->public_id,
            'version' => $link->row_version,
            'delivery' => Delivery::query()->find($link->create_delivery_id)?->public_id,
            'state' => $link->state,
        ];
    }
}
