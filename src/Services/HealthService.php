<?php

namespace TelescopeMonitor\Services;

use Illuminate\Support\Facades\Cache;
use Laravel\Telescope\Watchers\ExceptionWatcher;
use TelescopeMonitor\Foundation\PackagePath;
use TelescopeMonitor\Operations\OperationalStatus;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Repositories\Contracts\HealthCheckRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use Throwable;

final class HealthService
{
    public function __construct(
        private InstallationRepository $installations,
        private HealthCheckRepository $health,
        private PackagePath $path,
        private CaptureInboxRepository $inbox,
    ) {}

    public function inspect(bool $record = false): array
    {
        $storage = $this->installations->storageState();
        $watcher = config('telescope.watchers.'.ExceptionWatcher::class, false);
        $enabled = config('telescope.enabled') && (is_array($watcher) ? ($watcher['enabled'] ?? true) : (bool) $watcher);
        $connection = config('exception-monitor.queues.connection') ?? config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        $available = $storage === 'ready' && $this->inbox->ready();
        $status = $available ? $this->inbox->status() : [
            'pending' => 0,
            'failed' => 0,
            'last_received_at' => null,
            'last_processed_at' => null,
            'oldest_pending_at' => null,
            'last_error_code' => null,
        ];
        $observations = $storage === 'ready' ? $this->health->latest($this->installations->current()->id) : [];
        $checks = [
            $this->check('path', 'Dashboard path', $this->path->valid() ? 'passed' : 'failed', 'configuration', $this->path->valid() ? $this->path->base() : 'Choose a non-root path without wildcard characters.'),
            $this->check('storage', 'Package storage', $storage === 'ready' ? 'passed' : 'failed', 'observation', match ($storage) {
                'ready' => 'Foundation schema is available.',
                'prototype_detected' => 'Prototype tables detected. Back up and review conversion or use a fresh database.',
                default => 'Run exception-monitor:install, then exception-monitor:migrate.',
            }),
            $this->check('telescope', 'Telescope ExceptionWatcher', $enabled ? 'passed' : 'warning', 'configuration', $enabled ? 'Configured as enabled; host filters still determine acceptance.' : 'Enable Telescope and its ExceptionWatcher in host configuration.'),
            $this->check('queue', 'Queue connection', in_array($driver, ['database', 'redis'], true) ? 'passed' : 'warning', 'configuration', in_array($driver, ['database', 'redis'], true) ? 'Asynchronous driver configured. This does not prove worker activity.' : 'Configure an asynchronous database or Redis queue before enabling background processing.'),
            $this->check('capture', 'Exception capture', $status['last_received_at'] ? 'passed' : 'unverified', 'observation', ! $available ? 'Run the capture migrations before enabling ingestion.' : ($status['last_received_at'] ? 'Accepted exceptions have been durably buffered. Last receipt: '.$status['last_received_at'].' UTC.' : 'Capture is installed. No accepted exception has been observed yet.'), $status['last_received_at']),
            $this->check('worker', 'Worker processing', $status['failed'] > 0 ? 'warning' : ($status['last_processed_at'] ? 'passed' : 'unverified'), 'observation', $status['pending'].' pending; '.$status['failed'].' failed. '.($status['oldest_pending_at'] ? 'Oldest pending receipt: '.$status['oldest_pending_at'].' UTC. ' : '').($status['last_error_code'] ? 'Latest safe failure code: '.$status['last_error_code'].'. ' : '').($status['last_processed_at'] ? 'Last processing: '.$status['last_processed_at'].' UTC. This does not prove a currently running worker.' : 'No processing completion has been observed.'), $status['last_processed_at']),
            $this->check('ingestion', 'Source ingestion diagnostics', $observations['capture_ingestion']['state'] ?? 'unverified', 'observation', $observations['capture_ingestion']['sanitized_details']['detail'] ?? 'No capture adapter diagnostic has been recorded.', $observations['capture_ingestion']['observed_at'] ?? null),
            $this->check('reconciliation', 'Source reconciliation', $observations['capture_reconcile']['state'] ?? 'unverified', 'observation', $observations['capture_reconcile']['sanitized_details']['detail'] ?? 'No reconciliation sweep has been observed.', $observations['capture_reconcile']['observed_at'] ?? null),
            $this->check('scheduler', 'Capture recovery', isset($observations['capture_recovery']) ? $observations['capture_recovery']['state'] : 'unverified', 'observation', $observations['capture_recovery']['sanitized_details']['detail'] ?? 'No recovery sweep has been observed.', $observations['capture_recovery']['observed_at'] ?? null),
            $this->check('pruning', 'Retention pruning', isset($observations['capture_prune']) ? $observations['capture_prune']['state'] : 'unverified', 'observation', $observations['capture_prune']['sanitized_details']['detail'] ?? 'No pruning sweep has been observed.', $observations['capture_prune']['observed_at'] ?? null),
        ];
        if ($storage === 'ready' && app(AlertPlanning::class)->ready()) {
            $delivery = app(DeliveryLedger::class)->status();
            $checks[] = $this->check('email_worker', 'Delivery processing', ($delivery['failed'] || $delivery['needs_review']) ? 'warning' : (isset($observations['delivery_worker']) ? $observations['delivery_worker']['state'] : 'unverified'), 'observation', $delivery['pending'].' pending; '.$delivery['failed'].' failed; '.$delivery['needs_review'].' require review. Transport acceptance does not prove inbox receipt or a running worker.', $observations['delivery_worker']['observed_at'] ?? null);
            $checks[] = $this->check('email_recovery', 'Delivery recovery', $observations['delivery_recovery']['state'] ?? 'unverified', 'observation', $observations['delivery_recovery']['sanitized_details']['detail'] ?? 'No email recovery sweep has been observed.', $observations['delivery_recovery']['observed_at'] ?? null);
        }
        try {
            $paused = Cache::get('telescope:pause-recording');
            $checks[] = $this->check('recording', 'Telescope recording', $paused ? 'warning' : 'passed', 'configuration', $paused ? 'Recording is paused.' : 'No pause flag is set. Capture acceptance must still be verified.');
        } catch (Throwable) {
            $checks[] = $this->check('recording', 'Telescope recording', 'unverified', 'configuration', 'The pause flag could not be checked. Review host cache availability.');
        }
        if ($storage === 'ready' && app(RetentionPolicy::class)->ready()) {
            $operations = app(OperationalStatus::class);
            try {
                app(RetentionPolicy::class)->bounds();
            } catch (Throwable) {
                $checks[] = $this->check('retention_limits', 'Retention deployment limits', 'failed', 'configuration', 'Fix exception-monitor.retention_limits: minimum and maximum must form a supported range. No weaker limit is silently substituted.');
            }
            $schema = $operations->schema();
            $checks[] = $this->check('complete_schema', 'Complete package schema', $schema['ready'] ? 'passed' : 'failed', 'configuration', $schema['ready'] ? 'All 25 logical tables are available.' : 'Run exception-monitor:install and host migrations. Missing: '.implode(', ', $schema['missing']));
            if ($schema['ready']) {
                $source = $operations->source();
                $keys = $operations->credentials();
                $i = $this->installations->current();
                $replace = ['worker', 'recovery', 'reconcile', 'capture_prune', 'email_worker', 'email_recovery'];
                $checks = array_values(array_filter($checks, fn ($c) => ! in_array($c['key'], $replace, true)));
                foreach ($operations->observations() as $o) {
                    $key = [
                        'capture_worker' => 'worker',
                        'delivery_worker' => 'email_worker',
                        'capture_recovery' => 'recovery',
                        'delivery_recovery' => 'email_recovery',
                        'capture_reconcile' => 'reconcile',
                        'retention' => 'capture_prune',
                    ][$o['key']] ?? $o['key'];
                    $checks[] = $this->check($key, $o['label'], $o['state'], 'observation', $o['detail'], $o['observed_at']) + [
                        'last_started_at' => $o['last_started_at'],
                        'last_success_at' => $o['last_success_at'],
                        'last_failure_at' => $o['last_failure_at'],
                        'fresh' => $o['fresh'],
                    ];
                }
                $checks[] = $this->check('source_epoch', 'Telescope source coverage', $source['state'], 'observation', $source['detail']);
                $checks[] = $this->check('key_availability', 'Active credentials and keys', $keys['state'], 'configuration', $keys['detail']);
                $checks[] = $this->check('maintenance', 'Installation maintenance', $i->state === 'active' ? 'passed' : 'warning', 'observation', $i->state === 'active' ? 'No purge drain is active. This monitor shares host failure domains.' : 'The installation is '.$i->state.'. New package claims are stopped. Inspect the purge run and resume the deployment command.');
            }
        }
        if ($record && $storage === 'ready') {
            $this->health->record($this->installations->current()->id, $checks);
        }

        return $checks;
    }

    private function check(string $key, string $label, string $state, string $kind, string $detail, ?string $observed = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'kind' => $kind,
            'detail' => $detail,
            'observed_at' => $observed ?? now('UTC')->toIso8601String(),
        ];
    }
}
