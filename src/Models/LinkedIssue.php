<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int $issue_id
 * @property int $connection_id
 * @property int|null $create_delivery_id
 * @property int|null $remote_number
 * @property string|null $remote_url
 * @property string|null $last_known_remote_state
 * @property string $state
 * @property string $repository_owner
 * @property string $repository_name
 * @property string $operation_public_id
 * @property int $row_version
 * @property CarbonImmutable|null $checked_at
 */
final class LinkedIssue extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_linked_issues';

    protected $guarded = [];

    protected $attributes = ['row_version' => 1];

    protected $casts = [
        'installation_id' => 'integer',
        'issue_id' => 'integer',
        'connection_id' => 'integer',
        'create_delivery_id' => 'integer',
        'remote_number' => 'integer',
        'row_version' => 'integer',
        'checked_at' => 'immutable_datetime',
    ];
}
