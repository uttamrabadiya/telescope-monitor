<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_started_at
 * @property CarbonImmutable|null $last_failure_at
 * @property CarbonImmutable|null $observed_at
 * @property string|null $operation_public_id
 * @property int $id
 * @property string $state
 * @property array $sanitized_details
 */
final class HealthCheck extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_health_checks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sanitized_details' => 'array',
            'observed_at' => 'immutable_datetime',
            'last_started_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
        ];
    }
}
