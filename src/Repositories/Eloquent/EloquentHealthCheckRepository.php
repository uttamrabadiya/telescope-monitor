<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\HealthCheck;
use TelescopeMonitor\Repositories\Contracts\HealthCheckRepository;

final class EloquentHealthCheckRepository implements HealthCheckRepository
{
    public function record(int $installationId, array $checks): void
    {
        foreach ($checks as $check) {
            $row = HealthCheck::query()->firstOrNew([
                'installation_id' => $installationId,
                'check_key' => $check['key'],
            ]);
            $row->fill([
                'evidence_kind' => $check['kind'],
                'state' => $check['state'],
                'observed_at' => $check['observed_at'] ?? now('UTC'),
                'sanitized_details' => [
                    'label' => $check['label'],
                    'detail' => $check['detail'],
                    'metadata' => $check['metadata'] ?? [],
                    'generation' => $check['metadata']['generation'] ?? null,
                    'history' => $row->sanitized_details['history'] ?? [],
                ],
            ]);
            if (($check['phase'] ?? null) === 'started') {
                $row->last_started_at = CarbonImmutable::now('UTC');
            }
            if ($check['state'] === 'passed') {
                $row->last_success_at = CarbonImmutable::parse($check['observed_at'] ?? 'now', 'UTC');
            } elseif ($check['state'] === 'failed' || ($check['metadata']['attempt_failed'] ?? false)) {
                $row->last_failure_at = CarbonImmutable::parse($check['observed_at'] ?? 'now', 'UTC');
            }
            $row->save();
        }
    }

    public function latest(int $installationId): array
    {
        return HealthCheck::query()->where('installation_id', $installationId)->get()->keyBy('check_key')->toArray();
    }
}
