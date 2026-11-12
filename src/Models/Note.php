<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int $issue_id
 * @property string $author_actor_key
 * @property string $author_lookup_hash
 * @property string|null $body_ciphertext
 * @property int $body_version
 * @property int $row_version
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable $created_at
 */
final class Note extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'telescope_monitor_notes';

    protected $guarded = [];

    protected $attributes = ['row_version' => 1, 'body_version' => 1];

    protected $hidden = ['body_ciphertext'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'issue_id' => 'integer',
            'row_version' => 'integer',
            'body_version' => 'integer',
            'edited_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
