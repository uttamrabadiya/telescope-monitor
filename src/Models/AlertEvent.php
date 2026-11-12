<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int|null $issue_id
 * @property int $generation
 * @property string $event_type
 * @property int $event_version
 * @property string $event_key
 * @property int|null $occurrence_id
 * @property string|null $action_public_id
 * @property int $issue_state_version
 * @property int $alert_generation
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property string $provenance
 * @property string|null $actor_key
 * @property string $payload_ciphertext
 * @property int $payload_version
 * @property string $payload_digest
 * @property string $planning_state
 * @property CarbonImmutable $expires_at
 */
final class AlertEvent extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_alert_events';

    protected $guarded = [];

    protected $hidden = ['payload_ciphertext'];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'issue_id' => 'integer',
            'generation' => 'integer',
            'event_version' => 'integer',
            'occurrence_id' => 'integer',
            'issue_state_version' => 'integer',
            'alert_generation' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'payload_version' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
