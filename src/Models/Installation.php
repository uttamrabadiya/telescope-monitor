<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property CarbonImmutable|null $purged_through_at
 * @property string $source_epoch
 * @property string|null $source_signature
 * @property int $source_sequence_checkpoint
 * @property int $source_sequence_floor
 * @property int $id
 * @property string $public_id
 * @property string $state
 * @property int $config_revision
 * @property int $generation
 * @property array|null $setup_state
 */
final class Installation extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'exception_monitor_installations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'retention_cutoffs' => 'array', 'setup_state' => 'array',
            'generation' => 'integer',
            'config_revision' => 'integer',
            'purged_through_at' => 'immutable_datetime',
        ];
    }
}
