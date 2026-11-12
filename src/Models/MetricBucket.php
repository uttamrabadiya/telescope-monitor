<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property int $installation_id
 * @property int $issue_id
 * @property string $resolution
 * @property CarbonImmutable $bucket_start_at
 * @property int $occurrence_count
 * @property int $new_group_count
 * @property int $regression_count
 * @property CarbonImmutable $expires_at
 */
final class MetricBucket extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_metric_buckets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'issue_id' => 'integer',
            'bucket_start_at' => 'immutable_datetime',
            'occurrence_count' => 'integer',
            'new_group_count' => 'integer',
            'regression_count' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
