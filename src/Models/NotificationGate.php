<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property int $issue_id
 * @property string $target_fingerprint
 * @property string $event_family
 * @property int|null $reserved_delivery_id
 * @property string|null $reservation_token
 * @property CarbonImmutable|null $last_sent_at
 * @property CarbonImmutable|null $lease_until
 */
final class NotificationGate extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'telescope_monitor_notification_gates';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['payload_ciphertext'];

    protected $attributes = [];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'issue_id' => 'integer',
            'reserved_delivery_id' => 'integer',
            'last_sent_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
        ];
    }
}
