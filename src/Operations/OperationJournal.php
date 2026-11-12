<?php

namespace TelescopeMonitor\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CaptureFailure;
use TelescopeMonitor\Models\HealthCheck;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class OperationJournal
{
    public function __construct(private InstallationRepository $installations) {}

    private function write(string $name, string $run, string $state, array $metadata, int $generation, CarbonImmutable $started): void
    {
        Database::connection()->transaction(function () use ($name, $run, $state, $metadata, $generation, $started) {
            $i = $this->installations->fence();
            if ($i->generation !== $generation) {
                return;
            }
            $row = HealthCheck::query()->createOrFirst(['installation_id' => $i->id, 'check_key' => $name], ['evidence_kind' => 'observation', 'state' => 'unverified', 'sanitized_details' => []]);
            $row = HealthCheck::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $details = $row->sanitized_details;
            $history = $details['history'] ?? [];
            if ($state === 'running') {
                $row->operation_public_id = $run;
                $row->last_started_at = $started;
            } else {
                $history[] = [
                    'id' => $run,
                    'started_at' => $started->toISOString(),
                    'completed_at' => CarbonImmutable::now('UTC')->toISOString(),
                    'state' => $state,
                    'counts' => $metadata,
                ];
                $history = array_slice($history, -10);
                if ($state === 'passed') {
                    $row->last_success_at = CarbonImmutable::now('UTC');
                }if ($state === 'failed') {
                    $row->last_failure_at = CarbonImmutable::now('UTC');
                }
            }
            if ($state === 'running' || $row->operation_public_id === $run) {
                $row->state = $state;
                $row->observed_at = CarbonImmutable::now('UTC');
                $details['metadata'] = $metadata;
                $details['generation'] = $generation;
                $details['detail'] = $state === 'running' ? 'A maintenance attempt started; success is not yet observed.' : ($state === 'passed' ? 'The bounded maintenance operation completed.' : 'The maintenance operation failed; inspect the safe recovery guidance.');
            }
            $details['history'] = $history;
            $row->sanitized_details = $details;
            $row->save();
        }, 5);
    }

    public function run(string $name, callable $work): array
    {
        return Telescope::withoutRecording(function () use ($name, $work) {
            $run = (string) Str::uuid();
            $generation = $this->installations->current()->generation;
            $started = CarbonImmutable::now('UTC');
            $this->write($name, $run, 'running', [], $generation, $started);
            try {
                $result = $work();
                $current = HealthCheck::query()->where('installation_id', $this->installations->current()->id)->where('check_key', $name)->value('state');
                $this->write($name, $run, in_array($current, ['warning', 'failed'], true) ? $current : 'passed', $result, $generation, $started);

                return $result;
            } catch (\Throwable $e) {
                $this->write($name, $run, 'failed', ['safe_error_code' => 'maintenance_failed'], $generation, $started);
                if ($e instanceof CaptureFailure || $e instanceof MaintenanceFailure) {
                    throw $e;
                }
                throw new MaintenanceFailure('maintenance_failed');
            }
        });
    }
}
