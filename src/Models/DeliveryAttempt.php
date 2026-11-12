<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $delivery_id
 * @property int $sequence_number
 * @property int $retry_cycle
 * @property string $claim_token
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string $outcome
 * @property string|null $safe_error_code
 */
final class DeliveryAttempt extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'exception_monitor_delivery_attempts';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['payload_ciphertext'];

    protected $attributes = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'delivery_id' => 'integer',
            'sequence_number' => 'integer',
            'retry_cycle' => 'integer',
            'provider_status' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'retry_after_at' => 'immutable_datetime',
        ];
    }
}
