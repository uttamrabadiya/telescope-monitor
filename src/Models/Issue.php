<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int|string|null $range_count
 * @property string|null $effective_status
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property string $environment
 * @property bool $is_test
 * @property int $grouping_version
 * @property string $fingerprint_hash
 * @property string $exception_class
 * @property string $message
 * @property string $file
 * @property int $line
 * @property string $status
 * @property int $lifetime_count
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $resolution_cutoff_at
 * @property CarbonImmutable|null $snoozed_at
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $notification_cutoff_at
 * @property int $alert_generation
 * @property string|null $assignee_actor_key
 * @property string|null $assignee_lookup_hash
 * @property int $state_version
 * @property bool $recovered_only
 */
final class Issue extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'exception_monitor_issues';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'is_test' => 'boolean',
            'grouping_version' => 'integer',
            'line' => 'integer',
            'lifetime_count' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'resolution_cutoff_at' => 'immutable_datetime',
            'snoozed_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'notification_cutoff_at' => 'immutable_datetime',
            'alert_generation' => 'integer',
            'state_version' => 'integer',
            'recovered_only' => 'boolean',
        ];
    }
}
