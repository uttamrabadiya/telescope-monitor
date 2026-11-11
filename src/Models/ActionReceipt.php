<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property int $installation_id
 * @property string $public_id
 * @property int $generation
 * @property string $actor_key
 * @property string $state
 * @property CarbonImmutable $expires_at
 */
final class ActionReceipt extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'exception_monitor_action_receipts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
