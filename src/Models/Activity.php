<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int|null $issue_id
 * @property string $event_type
 * @property string $actor_kind
 * @property string|null $actor_key
 * @property string $subject_type
 * @property string $subject_public_id
 * @property string $action_public_id
 * @property CarbonImmutable $occurred_at
 * @property array $metadata
 * @property CarbonImmutable $expires_at
 */
final class Activity extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'exception_monitor_activity';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'issue_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
