<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property bool $enabled
 * @property string $name
 * @property array $event_types
 * @property array $conditions
 * @property int|null $threshold_count
 * @property int|null $window_seconds
 * @property int $cooldown_seconds
 * @property int $row_version
 * @property CarbonImmutable|null $deleted_at
 */
final class AlertRule extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'telescope_monitor_alert_rules';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['payload_ciphertext'];

    protected $attributes = ['row_version' => 1, 'definition_version' => 1];

    protected $casts = [
        'installation_id' => 'integer',
        'enabled' => 'boolean',
        'event_types' => 'array',
        'conditions' => 'array',
        'threshold_count' => 'integer',
        'window_seconds' => 'integer',
        'cooldown_seconds' => 'integer',
        'row_version' => 'integer',
        'definition_version' => 'integer',
        'deleted_at' => 'immutable_datetime',
    ];
}
