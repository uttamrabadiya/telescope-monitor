<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int $from_generation
 * @property int $to_generation
 * @property string $source_epoch
 * @property int $source_sequence_floor
 * @property CarbonImmutable|null $source_time_cutoff
 * @property string $state
 * @property string $current_step
 * @property array $deletion_counts
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $safe_error_code
 */
final class PurgeRun extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'exception_monitor_purge_runs';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'from_generation' => 'integer',
            'to_generation' => 'integer',
            'source_sequence_floor' => 'integer',
            'deletion_counts' => 'array',
            'source_time_cutoff' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
