<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Alerts\DeliveryDispatcher;
use TelescopeMonitor\Alerts\Envelope;
use TelescopeMonitor\Alerts\EventCatalog;
use TelescopeMonitor\Alerts\RuleDefinition;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Collaboration\Identity;
use TelescopeMonitor\Integrations\IntegrationManagement;
use TelescopeMonitor\Models\ActionReceipt;
use TelescopeMonitor\Models\Activity;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\AlertRule;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Delivery;
use TelescopeMonitor\Models\DeliveryAttempt;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Secret;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\AlertManagement;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\SettingsService;
use TelescopeMonitor\Storage\Database;

final class EloquentAlertManagement implements AlertManagement
{
    public function __construct(private InstallationRepository $installations, private AlertPlanning $planner, private EloquentDeliveryLedger $ledger, private Identity $identity, private Capabilities $caps, private RuleDefinition $definitions, private SettingsService $settings, private Envelope $envelopes, private DeliveryDispatcher $dispatcher) {}

    private function installation(): int
    {
        abort_unless($this->caps->allows('view'), 403);
        if (! $this->planner->ready()) {
            throw new Conflict('Publish and run the alerting migration first.');
        }

        return $this->installations->current()->id;
    }

    private function connection(Connection $c, bool $admin = false): array
    {
        $email = null;
        $state = 'configured';
        if ($c->provider !== 'email') {
            return app(IntegrationManagement::class)->summary($c) + ['email' => null];
        }
        if ($admin) {
            try {
                $secret = Secret::query()->where('installation_id', $c->installation_id)->where('connection_id', $c->id)->where('secret_name', 'email_address')->where('state', 'active')->firstOrFail();
                $email = Crypt::decryptString($secret->ciphertext);
            } catch (\Throwable) {
                $state = 'unreadable';
            }
        }

        return [
            'id' => $c->public_id,
            'provider' => $c->provider,
            'name' => $c->display_name,
            'email' => $email,
            'display' => $c->target_public['display'],
            'enabled' => $c->enabled,
            'disconnected' => $c->disconnected_at !== null,
            'version' => $c->row_version,
            'state' => $state,
            'last_test_at' => $c->last_test_at?->toISOString(),
            'last_test_outcome' => $c->last_test_outcome,
            'last_accepted_at' => $c->options['last_accepted_at'] ?? null,
            'failed_count' => (string) Delivery::query()->where('connection_id', $c->id)->where('status', 'failed')->count(),
        ];
    }

    private function rule(AlertRule $r): array
    {
        return [
            'id' => $r->public_id,
            'name' => $r->name,
            'enabled' => $r->enabled,
            'version' => $r->row_version,
            'definition_version' => $r->definition_version,
            'events' => $r->event_types,
            'conditions' => $r->conditions,
            'connections' => Connection::query()->join('telescope_monitor_rule_connections as rc', 'rc.connection_id', '=', 'telescope_monitor_connections.id')->where('rc.rule_id', $r->id)->pluck('public_id')->all(),
            'threshold_count' => $r->threshold_count,
            'window_seconds' => $r->window_seconds,
            'cooldown_seconds' => $r->cooldown_seconds,
        ];
    }

    public function configuration(): array
    {
        $i = $this->installation();
        abort_unless($this->caps->allows('manage'), 403);

        return [
            'rules' => AlertRule::query()->where('installation_id', $i)->whereNull('deleted_at')->orderBy('name')->orderBy('id')->limit(100)->get()->map(fn ($r) => $this->rule($r))->all(),
            'connections' => Connection::query()->where('installation_id', $i)->whereIn('provider', ['email', 'slack', 'webhook'])->orderBy('display_name')->orderBy('id')->limit(100)->get()->map(fn ($c) => $this->connection($c, true))->all(),
            'events' => EventCatalog::LABELS,
            'allowed_environments' => config('telescope-monitor.alerts.allowed_environments', ['production']),
            'enabled' => (bool) config('telescope-monitor.alerts.enabled', true),
            'queue_asynchronous' => $this->dispatcher->asynchronous(),
            'mailer' => config('telescope-monitor.alerts.mailer') ?? config('mail.default'),
            'max_cooldown_seconds' => min(86400, (int) config('telescope-monitor.alerts.max_cooldown_seconds', 86400)),
            'allowed_tags' => config('telescope-monitor.capture.allowed_tags', []),
            'starter' => [
                'name' => 'Production errors',
                'enabled' => false,
                'events' => ['error.created', 'error.regressed'],
                'conditions' => ['environments' => ['production'], 'class' => null, 'origin' => null, 'tag' => null],
                'connections' => [],
                'threshold_count' => null,
                'window_seconds' => null,
                'cooldown_seconds' => 1800,
            ],
        ];
    }

    private function resource(Delivery $d): array
    {
        $c = Connection::query()->where('installation_id', $d->installation_id)->find($d->connection_id);
        $e = AlertEvent::query()->where('installation_id', $d->installation_id)->find($d->event_id);
        $issue = $d->issue_id ? Issue::query()->where('installation_id', $d->installation_id)->find($d->issue_id) : null;

        return [
            'id' => $d->public_id,
            'status' => $d->status,
            'action' => $d->action,
            'event' => $e ? EventCatalog::canonical($e->event_type) : 'unavailable',
            'environment' => $issue->environment ?? 'test',
            'issue_id' => $issue?->public_id,
            'destination' => $c?->target_public['display'] ?? 'Unavailable target',
            'destination_name' => $c->display_name ?? 'Unavailable target',
            'channel' => $c->provider ?? 'email',
            'attempts' => $d->attempt_count_total,
            'cycle' => $d->retry_cycle,
            'cycle_attempts' => $d->cycle_attempts,
            'eligible_at' => $d->eligible_at->toISOString(),
            'expires_at' => $d->expires_at->toISOString(),
            'lease_until' => $d->lease_until?->toISOString(),
            'sent_at' => $d->sent_at?->toISOString(),
            'completed_at' => $d->completed_at?->toISOString(),
            'created_at' => $d->created_at->toISOString(),
            'reason' => $d->cancellation_reason ?? $d->last_error_code,
            'can_retry' => $this->caps->allows('operate') && ($d->action !== 'connection_test' || $this->caps->allows('manage')) && $d->status === 'failed',
            'can_review_resend' => ! str_starts_with($d->action, 'github_') && $this->caps->allows('operate') && ($d->action !== 'connection_test' || $this->caps->allows('manage')) && ($d->status === 'failed' || $d->cancellation_reason === 'export_policy_changed'),
        ];
    }

    public function history(array $f): array
    {
        $i = $this->installation();
        $q = Delivery::query()->where('installation_id', $i);
        if ($f['status'] ?? null) {
            $q->where('status', $f['status']);
        }if (isset($f['test'])) {
            $q->where('action', (bool) $f['test'] ? '=' : '!=', 'connection_test');
        }
        if ($f['issue'] ?? null) {
            $q->where('issue_id', Issue::query()->where('installation_id', $i)->where('public_id', $f['issue'])->value('id') ?? 0);
        }
        if ($f['rule'] ?? null) {
            $q->whereExists(fn ($q) => $q->selectRaw('1')->from('telescope_monitor_delivery_rules')->whereColumn('delivery_id', 'telescope_monitor_deliveries.id')->where('rule_public_id', $f['rule']));
        }
        if ($f['environment'] ?? null) {
            $q->whereIn('issue_id', Issue::query()->where('installation_id', $i)->where('environment', $f['environment'])->select('id'));
        }
        if ($f['channel'] ?? null) {
            $q->whereIn('connection_id', Connection::query()->where('installation_id', $i)->where('provider', $f['channel'])->select('id'));
        }
        foreach (['after' => '>=', 'before' => '<'] as $key => $operator) {
            if ($f[$key] ?? null) {
                $at = CarbonImmutable::parse($f[$key], 'UTC');
                if ($key === 'before') {
                    $at = $at->addDay();
                }$q->where('created_at', $operator, $at->format('Y-m-d H:i:s.u'));
            }
        }
        $rows = $q->orderByDesc('id')->paginate(25, ['*'], 'page', $f['page'] ?? 1);

        return [
            'items' => $rows->getCollection()->map(fn ($d) => $this->resource($d))->all(),
            'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => (string) $rows->total(),
            'as_of' => CarbonImmutable::now('UTC')->toISOString(),
            'acceptance_note' => 'Sent means transport acceptance, not inbox receipt. Notification recovery is at least once. Ambiguous GitHub creation is held for review instead of blindly retried.',
        ];
    }

    public function detail(string $id): array
    {
        $i = $this->installation();
        $d = Delivery::query()->where('installation_id', $i)->where('public_id', $id)->firstOrFail();
        $payload = null;
        $state = $d->payload_ciphertext ? 'frozen' : 'preview';
        try {
            if ($d->payload_ciphertext) {
                $json = Crypt::decryptString($d->payload_ciphertext);
                if (! hash_equals($d->payload_digest, hash('sha256', $json))) {
                    throw new \RuntimeException;
                }$payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $payload = $this->ledger->envelope($d);
            }
        } catch (\Throwable) {
            $state = 'unreadable';
        }

        return [
            'delivery' => $this->resource($d),
            'payload' => $payload,
            'payload_state' => $state,
            'payload_text' => $payload ? (str_starts_with($d->action, 'github_') ? ($payload['github_title']."\n\n".$payload['github_body']) : ($payload['webhook_body'] ?? Envelope::text($payload))) : null,
            'rules' => Database::connection()->table('telescope_monitor_delivery_rules')->where('delivery_id', $d->id)->get()->map(fn ($r) => [
                'id' => $r->rule_public_id,
                'version' => (int) $r->rule_version,
                'conditions' => json_decode($r->conditions_snapshot, true),
                'name' => AlertRule::query()->whereKey($r->rule_id)->value('name') ?? 'Removed rule',
            ])->all(),
            'attempts' => DeliveryAttempt::query()->where('installation_id', $i)->where('delivery_id', $d->id)->orderBy('sequence_number')->limit(200)->get()->map(fn ($a) => [
                'sequence' => $a->sequence_number,
                'cycle' => $a->retry_cycle,
                'started_at' => $a->started_at->toISOString(),
                'completed_at' => $a->completed_at?->toISOString(),
                'outcome' => $a->outcome,
                'status' => $a->getAttribute('provider_status'),
                'code' => $a->safe_error_code,
                'summary' => $a->getAttribute('sanitized_error_summary'),
                'retry_after_at' => $a->getAttribute('retry_after_at')?->toISOString(),
            ])->all(),
        ];
    }

    public function preview(array $definition, ?string $id): array
    {
        $i = $this->installation();
        abort_unless($this->caps->allows('manage'), 403);
        $rule = $this->definitions->validate($definition);
        $issue = $id ? Issue::query()->where('installation_id', $i)->where('public_id', $id)->firstOrFail() : null;
        $general = $this->settings->group('general')['values'];
        $envelope = $this->envelopes->sanitize([
            'version' => 1,
            'delivery_id' => 'preview',
            'event' => $rule['events'][0],
            'label' => EventCatalog::LABELS[$rule['events'][0]],
            'test' => true,
            'application' => $general['display_name'],
            'environment' => $issue->environment ?? $rule['conditions']['environments'][0],
            'class' => $issue->exception_class ?? 'SyntheticMonitorCheck',
            'message' => $issue->message ?? 'Synthetic delivery verification',
            'file' => $issue->file ?? 'app/Example.php',
            'line' => $issue->line ?? 1,
            'count' => (string) ($issue->lifetime_count ?? 1),
            'occurred_at' => CarbonImmutable::now('UTC')->toISOString(),
            'url' => rtrim($general['dashboard_url'], '/').($issue ? '/errors/'.$issue->public_id : '/setup'),
        ]);

        return [
            'envelope' => $envelope,
            'text' => Envelope::text($envelope),
            'digest' => hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'detail' => 'Preview only; nothing was queued or sent. Test sends always use synthetic content.',
        ];
    }

    private function version(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new Conflict('This configuration changed. Your draft is preserved; reload before saving.', ['version' => $actual]);
        }
    }

    private function currentConnection(int $i, string $id): Connection
    {
        return Connection::query()->where('installation_id', $i)->where('public_id', $id)->lockForUpdate()->firstOrFail();
    }

    private function audit(?int $issue, string $subject, string $type, string $action, string $actor, array $metadata): void
    {
        Activity::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $this->installations->current()->id,
            'issue_id' => $issue,
            'event_type' => $type,
            'actor_kind' => 'user',
            'actor_key' => $actor,
            'subject_type' => 'delivery_configuration',
            'subject_public_id' => $subject,
            'action_public_id' => $action,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => $metadata,
            'expires_at' => CarbonImmutable::now('UTC')->addDays(app(RetentionPolicy::class)->effective()['activity_days']),
        ]);
    }

    private function revalidate(int $installation, ?int $connection = null): void
    {
        $rows = Delivery::query()->where('installation_id', $installation)->whereIn('status', ['pending', 'retrying', 'failed'])->when($connection !== null, fn ($q) => $q->where('connection_id', $connection))->orderBy('id')->limit(1000)->get();
        foreach ($rows as $d) {
            $c = Connection::query()->find($d->connection_id);
            $issue = $d->issue_id ? Issue::query()->find($d->issue_id) : null;
            $valid = $c && $c->enabled && ! $c->disconnected_at && $this->ledger->support($d, $issue)['valid'];
            if (! $valid) {
                $d->status = 'cancelled';
                $d->cancellation_reason = 'configuration_changed';
                $d->completed_at = CarbonImmutable::now('UTC');
                $d->save();
            }
        }
    }

    public function command(string $op, ?string $id, string $key, array $v): array
    {
        $installation = $this->installation();
        abort_unless($this->caps->allows(in_array($op, ['retry', 'resend'], true) ? 'operate' : 'manage'), 403);
        $actor = $this->identity->current();
        $hash = $this->identity->hash($actor->key);
        if (isset($v['version'])) {
            $v['version'] = (int) $v['version'];
        }
        if (isset($v['enabled'])) {
            $v['enabled'] = (bool) $v['enabled'];
        }
        if (isset($v['name'])) {
            $v['name'] = trim($v['name']);
        }
        if ($op === 'connection.create') {
            $v['email'] = $this->definitions->email($v['email']);
        }
        if (in_array($op, ['rule.create', 'rule.update'], true)) {
            $v = array_merge($v, $this->definitions->validate($v));
        }
        if ($op === 'resend' && Delivery::query()->where('public_id', $id)->whereIn('action', ['github_create', 'github_auto'])->exists()) {
            throw new Conflict('Review a new GitHub creation through the linked issue panel.');
        }
        $digest = $this->identity->digest(['op' => $op, 'id' => $id, 'values' => $v]);

        return Database::connection()->transaction(function () use ($op, $id, $key, $v, $actor, $hash, $digest) {
            $i = $this->installations->lock();
            if ($i->state !== 'active') {
                throw new Conflict('Monitoring is undergoing maintenance.');
            }
            $receipt = ActionReceipt::query()->createOrFirst(['installation_id' => $i->id, 'actor_lookup_hash' => $hash, 'operation' => 'alerting.'.$op, 'client_key' => $key], [
                'public_id' => (string) Str::uuid(),
                'generation' => $i->generation,
                'actor_key' => $actor->key,
                'keyed_request_digest' => $digest,
                'state' => 'pending',
                'expires_at' => CarbonImmutable::now('UTC')->addDays(30),
            ]);
            $receipt = ActionReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->generation !== $i->generation || ! hash_equals($receipt->keyed_request_digest, $digest)) {
                throw new Conflict('This action key belongs to another request. Reload before retrying.');
            }
            if ($receipt->state === 'completed') {
                return ['id' => $receipt->result_public_id, 'version' => $receipt->resulting_row_version, 'replayed' => true];
            }
            $result = match (true) {
                str_starts_with($op, 'connection.') => $this->writeConnection($i->id, $op, $id, $v, $actor->key, $receipt->public_id),str_starts_with($op, 'rule.') => $this->writeRule($i->id, $op, $id, $v, $actor->key, $receipt->public_id),$op === 'test' => $this->test($i->id, $i->generation, $v, $actor->key, $receipt->public_id),$op === 'retry' || $op === 'resend' => $this->retry($i->id, $i->generation, $id, $op, $v, $actor->key, $receipt->public_id),default => throw new \InvalidArgumentException('Unknown alerting operation')
            };
            if (str_starts_with($op, 'connection.') || str_starts_with($op, 'rule.')) {
                $this->installations->advanceConfiguration($i);
                $this->revalidate($i->id);
            }
            $receipt->state = 'completed';
            $receipt->result_type = $result['type'];
            $receipt->result_public_id = $result['id'];
            $receipt->resulting_row_version = $result['version'] ?? null;
            $receipt->completed_at = CarbonImmutable::now('UTC');
            $receipt->save();

            return $result + ['replayed' => false];
        }, 5);
    }

    private function writeConnection(int $i, string $op, ?string $id, array $v, string $actor, string $action): array
    {
        if ($op === 'connection.create') {
            $fp = $this->identity->digest(['provider' => 'email', 'email' => $v['email']]);
            $c = Connection::query()->createOrFirst(['installation_id' => $i, 'provider' => 'email', 'target_fingerprint' => $fp], [
                'public_id' => (string) Str::uuid(),
                'display_name' => trim($v['name']),
                'enabled' => (bool) $v['enabled'],
                'target_public' => ['display' => $this->definitions->mask($v['email'])],
                'options' => [],
            ]);
            if ($c->wasRecentlyCreated) {
                Secret::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'installation_id' => $i,
                    'connection_id' => $c->id,
                    'owner_key' => 'connection:'.$c->public_id,
                    'secret_name' => 'email_address',
                    'revision' => 1,
                    'key_id' => (string) Str::uuid(),
                    'source' => 'database',
                    'ciphertext' => Crypt::encryptString($v['email']),
                    'state' => 'active',
                    'activated_at' => CarbonImmutable::now('UTC'),
                    'created_by_actor_key' => $actor,
                ]);
            }
            if ($v['replaces'] ?? null) {
                $old = $this->currentConnection($i, $v['replaces']);
                if ($old->id === $c->id) {
                    throw new Conflict('The recipient is unchanged. Edit its label/availability instead.');
                }$this->version($old->row_version, (int) $v['replaces_version']);
                $old->enabled = false;
                $old->disconnected_at = CarbonImmutable::now('UTC');
                $old->row_version++;
                $old->save();
                if ($c->wasRecentlyCreated) {
                    $c->setAttribute('replaces_connection_id', $old->id);
                    $c->save();
                }
            }
        } else {
            $c = $this->currentConnection($i, $id);
            $this->version($c->row_version, $v['version']);
            if ($op === 'connection.disconnect') {
                $c->enabled = false;
                $c->disconnected_at = CarbonImmutable::now('UTC');
            } else {
                $c->display_name = trim($v['name']);
                $c->enabled = (bool) $v['enabled'];
                if ($c->enabled) {
                    $c->disconnected_at = null;
                }
            }$c->row_version++;
            $c->save();
        }
        $this->audit(null, $c->public_id, $op, $action, $actor, []);

        return ['type' => 'connection', 'id' => $c->public_id, 'version' => $c->row_version];
    }

    private function writeRule(int $i, string $op, ?string $id, array $v, string $actor, string $action): array
    {
        if ($op === 'rule.create') {
            if (AlertRule::query()->where('installation_id', $i)->whereNull('deleted_at')->count() >= 100) {
                throw ValidationException::withMessages(['name' => 'At most 100 rules are supported.']);
            }$r = new AlertRule(['public_id' => (string) Str::uuid(), 'installation_id' => $i, 'created_by_actor_key' => $actor]);
        } else {
            $r = AlertRule::query()->where('installation_id', $i)->where('public_id', $id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            $this->version($r->row_version, $v['version']);
            $r->row_version++;
        }
        if ($op === 'rule.delete') {
            $r->enabled = false;
            $r->deleted_at = CarbonImmutable::now('UTC');
            $r->save();
        } else {
            $connections = Connection::query()->where('installation_id', $i)->whereIn('provider', ['email', 'slack', 'webhook'])->whereIn('public_id', $v['connections'])->whereNull('disconnected_at')->get();
            if ($connections->count() !== count($v['connections'])) {
                throw ValidationException::withMessages(['connections' => 'One or more selected recipients are unavailable.']);
            }if ($v['enabled'] && $connections->contains(fn ($c) => ! $c->enabled)) {
                throw ValidationException::withMessages(['connections' => 'Enable selected recipients before enabling this rule.']);
            }$r->name = $v['name'];
            $r->enabled = $v['enabled'];
            $r->event_types = $v['events'];
            $r->conditions = $v['conditions'];
            $r->threshold_count = $v['threshold_count'];
            $r->window_seconds = $v['window_seconds'];
            $r->cooldown_seconds = $v['cooldown_seconds'];
            $r->save();
            Database::connection()->table('telescope_monitor_rule_connections')->where('rule_id', $r->id)->delete();
            foreach ($connections as $c) {
                Database::connection()->table('telescope_monitor_rule_connections')->insert(['rule_id' => $r->id, 'connection_id' => $c->id]);
            }
        }
        $this->audit(null, $r->public_id, $op, $action, $actor, []);

        return ['type' => 'rule', 'id' => $r->public_id, 'version' => $r->row_version];
    }

    private function test(int $i, int $generation, array $v, string $actor, string $action): array
    {
        if (! config('telescope-monitor.alerts.enabled', true)) {
            throw new Conflict('Delivery is disabled by deployment policy.');
        }
        $connections = Connection::query()->where('installation_id', $i)->whereIn('public_id', $v['connections'])->where('enabled', true)->whereNull('disconnected_at')->get();
        if ($connections->count() !== count($v['connections']) || $connections->isEmpty()) {
            throw ValidationException::withMessages(['connections' => 'Choose enabled recipients for the explicit synthetic test.']);
        }
        $json = json_encode([
            'class' => 'SyntheticMonitorCheck',
            'message' => 'This is a synthetic integration delivery check.',
            'file' => '',
            'line' => 0,
            'count' => '0',
        ], JSON_THROW_ON_ERROR);
        $event = AlertEvent::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $i,
            'generation' => $generation,
            'event_type' => 'monitor.test',
            'event_key' => hash('sha256', 'test:'.$action),
            'action_public_id' => $action,
            'issue_state_version' => 0,
            'alert_generation' => 0,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'recorded_at' => CarbonImmutable::now('UTC'),
            'provenance' => 'synthetic_test',
            'actor_key' => $actor,
            'payload_ciphertext' => Crypt::encryptString($json),
            'payload_digest' => hash('sha256', $json),
            'planning_state' => 'planned',
            'expires_at' => CarbonImmutable::now('UTC')->addDays(30),
        ]);
        foreach ($connections as $c) {
            $d = Delivery::query()->create([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i,
                'generation' => $generation,
                'event_id' => $event->id,
                'connection_id' => $c->id,
                'action' => 'connection_test',
                'dedupe_key' => hash('sha256', 'test:'.$action.':'.$c->target_fingerprint),
                'target_fingerprint' => $c->target_fingerprint,
                'actor_required' => true,
                'actor_key' => $actor,
                'event_family' => 'test',
                'status' => 'pending',
                'eligible_at' => CarbonImmutable::now('UTC'),
                'expires_at' => CarbonImmutable::now('UTC')->addDay(),
            ]);
            if (isset($v['signing_key_id'])) {
                $d->setAttribute('credential_key_id', $v['signing_key_id']);
                $d->save();
            }
            $c->last_test_at = CarbonImmutable::now('UTC');
            $c->last_test_outcome = 'pending';
            $c->save();
            Database::connection()->afterCommit(fn () => $this->dispatcher->dispatch($d->id, $d->generation));
        }

        return ['type' => 'test_batch', 'id' => $action];
    }

    public function operation(string $id): array
    {
        $i = $this->installation();
        abort_unless($this->caps->allows('manage'), 403);
        $event = AlertEvent::query()->where('installation_id', $i)->where('action_public_id', $id)->where('event_type', 'monitor.test')->firstOrFail();

        return [
            'id' => $id,
            'items' => Delivery::query()->where('event_id', $event->id)->get()->map(fn ($d) => $this->resource($d))->all(),
            'queue_asynchronous' => $this->dispatcher->asynchronous(),
        ];
    }

    public function reviewPreview(string $id): array
    {
        $i = $this->installation();
        abort_unless($this->caps->allows('operate'), 403);
        $d = Delivery::query()->where('installation_id', $i)->where('public_id', $id)->firstOrFail();
        $e = $this->ledger->envelope($d);
        $json = json_encode($e, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return ['envelope' => $e, 'text' => Envelope::text($e), 'digest' => hash('sha256', $json)];
    }

    private function retry(int $i, int $generation, string $id, string $op, array $v, string $actor, string $action): array
    {
        $d = Delivery::query()->where('installation_id', $i)->where('public_id', $id)->firstOrFail();
        if ($d->issue_id) {
            Issue::query()->whereKey($d->issue_id)->lockForUpdate()->first();
        }
        // Exclusive installation fence serializes this administrative retry against claims.
        $d = Delivery::query()->whereKey($d->id)->lockForUpdate()->firstOrFail();
        $c = Connection::query()->findOrFail($d->connection_id);
        $issue = $d->issue_id ? Issue::query()->find($d->issue_id) : null;
        if ($d->action === 'connection_test') {
            abort_unless($this->caps->allows('manage'), 403);
        }
        if (! $c->enabled || $c->disconnected_at || $d->generation !== $generation || ! $this->ledger->support($d, $issue)['valid']) {
            throw new Conflict('This delivery is no longer applicable. Review the issue, rule and recipient.');
        }
        if ($op === 'retry') {
            if ($d->status !== 'failed' || $d->expires_at <= CarbonImmutable::now('UTC')) {
                throw new Conflict('Only still-applicable failed deliveries can be retried.');
            }
            if ($d->payload_ciphertext) {
                $json = Crypt::decryptString($d->payload_ciphertext);
                $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if ($payload !== $this->envelopes->sanitize($payload)) {
                    throw new Conflict('Export policy changed. Preview and confirm a separately reviewed resend.');
                }
            }
            $d->status = 'pending';
            $d->retry_cycle++;
            $d->cycle_attempts = 0;
            $d->completed_at = null;
            $d->last_error_code = null;
            $d->actor_required = true;
            $d->actor_key = $actor;
            $d->eligible_at = CarbonImmutable::now('UTC');
            $d->save();
        } else {
            if ($d->status !== 'failed' && $d->cancellation_reason !== 'export_policy_changed') {
                throw new Conflict('This delivery is not eligible for reviewed resend.');
            }
            $body = $this->ledger->envelope($d);
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (! hash_equals(hash('sha256', $json), $v['preview_digest'])) {
                throw new Conflict('The reviewed payload changed. Preview it again.');
            }
            $old = $d;
            $d = $old->replicate([
                'public_id',
                'sent_at',
                'claim_token',
                'lease_until',
                'payload_ciphertext',
                'payload_digest',
                'payload_frozen_at',
                'export_policy_revision',
                'completed_at',
                'last_error_code',
                'cancellation_reason',
                'attempt_count_total',
                'cycle_attempts',
            ]);
            $d->public_id = (string) Str::uuid();
            $d->revision = (int) Delivery::query()->where('installation_id', $i)->where('generation', $generation)->where('dedupe_key', $old->dedupe_key)->max('revision') + 1;
            $d->parent_delivery_id = $old->id;
            $d->status = 'pending';
            $d->attempt_count_total = 0;
            $d->cycle_attempts = 0;
            $d->retry_cycle = 1;
            $d->actor_required = true;
            $d->actor_key = $actor;
            $d->eligible_at = CarbonImmutable::now('UTC');
            $d->expires_at = CarbonImmutable::now('UTC')->addDay();
            $d->save();
            // A reviewed identity freezes its previewed bytes, with only the new logical ID changed.
            $body['delivery_id'] = $d->public_id;
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $d->payload_ciphertext = Crypt::encryptString($json);
            $d->payload_digest = hash('sha256', $json);
            $d->payload_frozen_at = CarbonImmutable::now('UTC');
            $d->export_policy_revision = $this->installations->current()->config_revision;
            $d->save();
            foreach (Database::connection()->table('telescope_monitor_delivery_rules')->where('delivery_id', $old->id)->get() as $ref) {
                $row = (array) $ref;
                $row['delivery_id'] = $d->id;
                Database::connection()->table('telescope_monitor_delivery_rules')->insert($row);
            }
        }
        $this->audit($d->issue_id, $d->public_id, 'delivery.'.$op, $action, $actor, ['delivery_id' => $d->public_id, 'retry_cycle' => $d->retry_cycle]);
        Database::connection()->afterCommit(fn () => $this->dispatcher->dispatch($d->id, $d->generation));

        return ['type' => 'delivery', 'id' => $d->public_id];
    }
}
