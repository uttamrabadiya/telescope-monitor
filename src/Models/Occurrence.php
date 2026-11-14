<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int $issue_id
 * @property string $source_epoch
 * @property string $source_uuid
 * @property int|null $source_sequence
 * @property string|null $batch_uuid
 * @property CarbonImmutable $occurred_at
 * @property string $time_precision
 * @property CarbonImmutable $received_at
 * @property string $origin
 * @property string $mode
 * @property bool $is_test
 */
final class Occurrence extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_occurrences';

    protected $guarded = [];

    protected $casts = [
        'installation_id' => 'integer',
        'issue_id' => 'integer',
        'source_sequence' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'is_test' => 'boolean',
    ];
}
