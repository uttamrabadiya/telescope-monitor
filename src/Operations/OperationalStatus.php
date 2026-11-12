<?php

namespace TelescopeMonitor\Operations;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Foundation\ConfigurationConflict;
use TelescopeMonitor\Integrations\Credentials;
use TelescopeMonitor\Models\ActionReceipt;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Models\Secret;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Repositories\Contracts\HealthCheckRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\SettingsService;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Telescope\StoredEntries;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;

final class OperationalStatus
{
    public function __construct(private InstallationRepository $installations, private HealthCheckRepository $health, private SettingsService $settings, private PurgeOperations $purge, private RetentionPolicy $retention, private Capabilities $caps, private StoredEntries $source, private StoredExceptionAdapter $capture) {}

    public function schema(): array
    {
        $expected = array_merge(PurgeOperations::TABLES, [
            'installations',
            'settings',
            'health_checks',
            'action_receipts',
            'connections',
            'secrets',
            'alert_rules',
            'rule_connections',
            'saved_views',
            'view_preferences',
            'purge_runs',
        ]);
        $missing = [];
        foreach ($expected as $table) {
            if (! Database::schema()->hasTable('telescope_monitor_'.$table)) {
                $missing[] = $table;
            }
        }

        return ['ready' => ! $missing, 'missing' => $missing, 'version' => 6];
    }

    public function source(): array
    {
        $i = $this->installations->current();
        try {
            $max = $this->source->maximum();
            $signature = $this->source->signature();
            $state = ($i->source_signature !== null && ! hash_equals($i->source_signature, $signature)) || $max < (int) $i->source_sequence_checkpoint ? 'failed' : ($i->source_signature ? 'passed' : 'unverified');

            return [
                'state' => $state,
                'epoch' => $i->source_epoch,
                'checkpoint' => (int) $i->source_sequence_checkpoint,
                'floor' => (int) $i->source_sequence_floor,
                'maximum' => $max,
                'cutoff' => $i->purged_through_at?->toISOString(),
                'detail' => $state === 'failed' ? 'Source identity/sequence changed. Review and explicitly rebaseline; no purge protection is silently removed.' : 'Only supported signed markers can be recovered. Pruned, filtered, unmarked and old-epoch rows are outside coverage.',
            ];
        } catch (\Throwable) {
            return [
                'state' => 'failed',
                'detail' => 'Telescope source could not be inspected. Restore storage access; do not reset cutoffs to bypass it.',
            ];
        }
    }

    public function credentials(): array
    {
        $i = $this->installations->current();
        $rows = Secret::query()->where('installation_id', $i->id)->where('state', 'active')->orderBy('id')->limit(101)->get();
        $unreadable = 0;
        foreach ($rows->take(100) as $s) {
            try {
                app(Credentials::class)->value($s);
            } catch (\Throwable) {
                $unreadable++;
            }
        }

        return [
            'state' => $unreadable ? 'failed' : ($rows->isEmpty() || $rows->count() > 100 ? 'unverified' : 'passed'),
            'checked' => min(100, $rows->count()),
            'unreadable' => $unreadable,
            'bounded' => $rows->count() > 100,
            'detail' => $unreadable ? 'An active key/reference is unreadable. Restore the host APP_KEY/previous keys or explicitly replace the affected integration credential. Nothing was discarded.' : 'A bounded local decryption/reference check; this does not test provider permissions or delivery.',
        ];
    }

    public function observations(): array
    {
        $i = $this->installations->current();
        $checks = $this->health->latest($i->id);
        $fresh = max(30, min(86400, (int) config('telescope-monitor.operations.freshness_seconds', 300)));
        $maintenance = max(60, min(604800, (int) config('telescope-monitor.operations.maintenance_seconds', 3600)));
        $capture = app(CaptureInboxRepository::class)->status();
        $delivery = app(DeliveryLedger::class)->status();
        $out = [];
        foreach ([
            'capture_worker' => 'Capture processing',
            'capture_import' => 'Telescope importer',
            'delivery_worker' => 'Delivery processing',
            'heartbeat' => 'Local maintenance heartbeat',
            'key_rewrap' => 'Encryption key maintenance',
            'retention' => 'Retention sweep',
            'capture_recovery' => 'Capture recovery',
            'delivery_recovery' => 'Delivery recovery',
            'capture_reconcile' => 'Source reconciliation',
        ] as $key => $label) {
            $c = $checks[$key] ?? [];
            $last = $c['last_success_at'] ?? null;
            $age = $last ? max(0, CarbonImmutable::parse($last, 'UTC')->diffInSeconds(CarbonImmutable::now('UTC'), false)) : null;
            $limit = in_array($key, ['retention', 'capture_reconcile'], true) ? $maintenance : $fresh;
            $prior = ! empty($c) && ($c['sanitized_details']['generation'] ?? null) !== $i->generation;
            $state = $prior ? 'unverified' : ($c['state'] ?? 'unverified');
            if ($age !== null && $age > $limit && $state === 'passed') {
                $state = 'warning';
            }
            if ($state === 'running' && (! isset($c['last_started_at']) || CarbonImmutable::parse($c['last_started_at'], 'UTC')->addSeconds($limit)->isPast())) {
                $state = 'warning';
            }
            $detail = $key === 'heartbeat' ? 'A local command observation only. It may be invoked manually and does not prove every scheduled task or independent host availability.' : 'A start is not completion. A past success does not prove a currently running worker; quiet traffic can leave an old observation.';
            if (! in_array($key, ['capture_worker', 'delivery_worker', 'heartbeat'], true)) {
                $detail = match ($key) {
                    'retention' => 'A bounded sweep; not proof that every eligible row has been removed. Summaries and replay identity are preserved.','key_rewrap' => 'A bounded re-encryption batch. Unreadable ciphertext is preserved; verify every target before retiring previous keys.','capture_reconcile' => 'A bounded signed-marker scan; source pruning, filters, epochs and revisit limits constrain coverage.',default => 'A bounded recovery/dispatch sweep. Completion does not prove a worker processed every job.'
                };
            }
            if (in_array($key, ['capture_worker', 'delivery_worker'], true)) {
                $isCapture = $key === 'capture_worker';
                $backlog = $isCapture ? $capture : $delivery;
                $table = $isCapture ? 'capture_inbox' : 'deliveries';
                $column = $isCapture ? 'state' : 'status';
                $expired = Database::connection()->table('telescope_monitor_'.$table)->where('installation_id', $i->id)->where($column, 'processing')->where('lease_until', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))->count();
                $review = $backlog['needs_review'] ?? 0;
                $detail = $backlog['pending'].' pending; '.$backlog['failed'].' failed; '.$review.' require review; '.$expired.' expired leases. '.$detail;
                $oldest = $backlog[$isCapture ? 'oldest_pending_at' : 'oldest_pending_at'] ?? null;
                if ($backlog['failed'] || $review || $expired || ($oldest && CarbonImmutable::parse($oldest, 'UTC')->addSeconds($limit)->isPast())) {
                    $state = 'warning';
                }
            }
            $out[] = [
                'key' => $key,
                'label' => $label,
                'state' => $state,
                'last_started_at' => $c['last_started_at'] ?? null,
                'last_success_at' => $last,
                'last_failure_at' => $c['last_failure_at'] ?? null,
                'observed_at' => $c['observed_at'] ?? null,
                'fresh' => $age !== null && $age <= $limit && ! $prior && $state === 'passed',
                'freshness_seconds' => $limit,
                'detail' => $detail,
                'history' => $c['sanitized_details']['history'] ?? [],
            ];
        }

        return $out;
    }

    public function setup(): array
    {
        $i = $this->installations->current();
        $state = $i->setup_state ?? [];
        $ack = $state['acknowledged'] ?? [];
        $general = $this->settings->group('general');
        $privacy = $this->settings->group('privacy');
        $capture = false;
        $probeId = null;
        $rows = ActionReceipt::query()->where('installation_id', $i->id)->where('operation', 'capture_probe')->where('generation', $i->generation)->where('state', 'completed')->where('completed_at', '>=', CarbonImmutable::now('UTC')->subDay()->format('Y-m-d H:i:s.u'))->orderByDesc('id')->limit(20)->get();
        foreach ($rows as $row) {
            $o = Occurrence::query()->where('installation_id', $i->id)->where('public_id', $row->result_public_id)->where('source_epoch', $i->source_epoch)->where('is_test', true)->first();
            if ($o && (! $i->purged_through_at || $o->occurred_at > $i->purged_through_at) && (app(EvidenceReader::class)->read($o->public_id)['evidence_state'] ?? null) === 'available') {
                $capture = true;
                $probeId = $row->public_id;
                break;
            }
        }
        $accepted = false;
        $verifiedId = null;
        $verifiedDestination = null;
        $tests = Database::connection()->table('telescope_monitor_deliveries as d')->join('telescope_monitor_connections as c', 'c.id', '=', 'd.connection_id')->where('d.installation_id', $i->id)->where('d.generation', $i->generation)->where('d.action', 'connection_test')->where('d.status', 'sent')->whereIn('c.provider', ['email', 'slack', 'webhook'])->where('c.enabled', true)->whereNull('c.disconnected_at')->where('d.completed_at', '>=', CarbonImmutable::now('UTC')->subDay()->format('Y-m-d H:i:s.u'))->orderByDesc('d.id')->limit(25)->get(['d.id', 'd.public_id', 'd.connection_id', 'c.display_name']);
        foreach ($tests as $test) {
            try {
                $connection = Connection::query()->findOrFail($test->connection_id);
                $target = app(Credentials::class)->target($connection);
                $key = $target['signing_key_id'] ?? $target['key_id'];
                if (Database::connection()->table('telescope_monitor_delivery_attempts')->where('delivery_id', $test->id)->where('outcome', 'accepted')->where('used_secret_key_id', $key)->exists()) {
                    $accepted = true;
                    $verifiedId = $test->public_id;
                    $verifiedDestination = $test->display_name;
                    break;
                }
            } catch (\Throwable) {
            }
        }
        $choice = $state['notification_choice'] ?? null;
        $requirements = [
            'installation' => $this->schema()['ready'] && $this->capture->enabled() && $i->state === 'active',
            'application' => ($ack['application'] ?? null) === $general['version'],
            'privacy' => ($ack['privacy'] ?? null) === $privacy['version'],
            'capture' => $capture,
            'notifications' => $choice === 'skip' || ($choice === 'verify' && $accepted),
            'operations' => ($ack['operations'] ?? false) === true,
        ];
        $complete = isset($state['completed_at']) && ($state['completed_generation'] ?? null) === $i->generation && ($state['completed_epoch'] ?? null) === $i->source_epoch;
        $rules = Database::connection()->table('telescope_monitor_alert_rules')->where('installation_id', $i->id)->where('enabled', true)->whereNull('deleted_at')->count();

        return [
            'requirements' => $requirements,
            'can_finish' => ! in_array(false, $requirements, true),
            'complete' => $complete,
            'completed_at' => $complete ? $state['completed_at'] : null,
            'notification_choice' => $choice,
            'delivery_verified' => $accepted,
            'delivery_verified_id' => $verifiedId,
            'delivery_destination' => $verifiedDestination,
            'capture_probe' => $probeId,
            'active_rules' => $rules,
            'optional' => ['automatic_alerts' => $rules > 0, 'assignments' => (bool) config('telescope-monitor.assignments.enabled', false)],
            'note' => 'Completion records your review and current-generation tests, not independent uptime monitoring. Optional notifications and assignments can remain unconfigured.',
        ];
    }

    public function acknowledge(array $v): array
    {
        abort_unless($this->caps->allows('manage'), 403);
        Database::connection()->transaction(function () use ($v) {
            $i = $this->installations->lock();
            if ($i->state !== 'active') {
                throw new ConfigurationConflict('Maintenance is in progress. Setup was not changed.');
            }if (isset($v['config_revision']) && (int) $v['config_revision'] !== $i->config_revision) {
                throw new ConfigurationConflict('Configuration changed. Reload and review current settings.');
            }$state = $i->setup_state ?? [];
            if (isset($v['step'])) {
                $state['current_step'] = (int) $v['step'];
            }if (isset($v['acknowledge'])) {
                $key = $v['acknowledge'];
                $state['acknowledged'][$key] = $key === 'operations' ? true : $this->settings->group($key === 'application' ? 'general' : 'privacy')['version'];
            }if (isset($v['notification_choice'])) {
                $state['notification_choice'] = $v['notification_choice'];
            }$this->installations->saveSetup($i, $state);
        }, 5);

        return $this->setup();
    }

    public function finish(): array
    {
        abort_unless($this->caps->allows('manage'), 403);
        Database::connection()->transaction(function () {
            $i = $this->installations->lock();
            $status = $this->setup();
            if ($status['complete']) {
                return;
            }if (! $status['can_finish']) {
                throw new ConfigurationConflict('Complete the required reviews and current capture/delivery checks before finishing.');
            }$state = $i->setup_state ?? [];
            $state['completed_at'] = CarbonImmutable::now('UTC')->toISOString();
            $state['completed_generation'] = $i->generation;
            $state['completed_epoch'] = $i->source_epoch;
            $state['current_step'] = 7;
            $this->installations->saveSetup($i, $state);
        }, 5);

        return $this->setup();
    }

    public function read(): array
    {
        $i = $this->installations->current();

        $connection = config('telescope-monitor.queues.connection') ?? config('queue.default');
        $worker = 'php artisan queue:work '.escapeshellarg((string) $connection).' --queue=';

        return [
            'installation' => $i->public_id,
            'state' => $i->state,
            'generation' => $i->generation,
            'schema' => $this->schema(),
            'source' => $this->source(),
            'credentials' => $this->credentials(),
            'observations' => $this->observations(),
            'setup' => $this->setup(),
            'purge' => $this->purge->status()['run'],
            'retention' => $this->settings->group('retention'),
            'limits' => $this->retention->bounds(),
            'as_of' => CarbonImmutable::now('UTC')->toISOString(),
            'commands' => [
                'capture_worker' => $worker.escapeshellarg((string) config('telescope-monitor.queues.capture', 'telescope-monitor-capture')).' --timeout=60 --tries=1',
                'delivery_worker' => $worker.escapeshellarg((string) config('telescope-monitor.queues.delivery', 'telescope-monitor-deliveries')).' --timeout=45 --tries=1',
                'heartbeat' => 'php artisan telescope-monitor:heartbeat',
                'retention' => 'php artisan telescope-monitor:prune --limit=1000',
                'recovery' => 'php artisan telescope-monitor:recover --limit=100',
                'delivery_recovery' => 'php artisan telescope-monitor:recover-deliveries --limit=100',
                'key_rewrap' => 'php artisan telescope-monitor:rewrap '.$i->public_id.' secrets --confirm='.$i->public_id.' --limit=100',
                'source_baseline' => 'php artisan telescope-monitor:source-baseline '.$i->public_id.' --confirm='.$i->public_id,
                'purge_preview' => 'php artisan telescope-monitor:purge '.$i->public_id.' --preview',
            ],
            'offline_note' => 'This package shares its host database, application and queues. Detecting a completely offline host requires an external observer.',
        ];
    }
}
