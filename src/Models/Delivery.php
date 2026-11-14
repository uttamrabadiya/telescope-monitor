<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int $generation
 * @property int|null $issue_id
 * @property int $event_id
 * @property int $connection_id
 * @property string $action
 * @property string $dedupe_key
 * @property int $revision
 * @property int|null $parent_delivery_id
 * @property string $target_fingerprint
 * @property string|null $actor_key
 * @property bool $actor_required
 * @property string $event_family
 * @property int $alert_generation
 * @property string $status
 * @property string|null $cancellation_reason
 * @property CarbonImmutable $eligible_at
 * @property CarbonImmutable $expires_at
 * @property int $attempt_count_total
 * @property int $cycle_attempts
 * @property int $retry_cycle
 * @property string|null $claim_token
 * @property CarbonImmutable|null $lease_until
 * @property string|null $payload_ciphertext
 * @property string|null $payload_digest
 * @property CarbonImmutable|null $payload_frozen_at
 * @property int|null $export_policy_revision
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $last_error_code
 * @property CarbonImmutable $created_at
 */
final class Delivery extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'telescope_monitor_deliveries';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['payload_ciphertext'];

    protected $attributes = ['revision' => 1, 'attempt_count_total' => 0, 'cycle_attempts' => 0, 'retry_cycle' => 1, 'payload_version' => 1];

    protected $casts = [
        'installation_id' => 'integer',
        'generation' => 'integer',
        'issue_id' => 'integer',
        'event_id' => 'integer',
        'connection_id' => 'integer',
        'revision' => 'integer',
        'parent_delivery_id' => 'integer',
        'actor_required' => 'boolean',
        'alert_generation' => 'integer',
        'attempt_count_total' => 'integer',
        'cycle_attempts' => 'integer',
        'retry_cycle' => 'integer',
        'payload_version' => 'integer',
        'export_policy_revision' => 'integer',
        'eligible_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'lease_until' => 'immutable_datetime',
        'payload_frozen_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
