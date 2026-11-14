<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int|null $connection_id
 * @property string $owner_key
 * @property string $secret_name
 * @property int $revision
 * @property string $key_id
 * @property string $source
 * @property string|null $deployment_reference
 * @property string|null $ciphertext
 * @property int $encryption_version
 * @property string $state
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $retire_after
 * @property string|null $created_by_actor_key
 */
final class Secret extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_secrets';

    protected $guarded = [];

    protected $hidden = ['ciphertext'];

    protected $casts = [
        'installation_id' => 'integer',
        'connection_id' => 'integer',
        'revision' => 'integer',
        'encryption_version' => 'integer',
        'activated_at' => 'immutable_datetime',
        'retire_after' => 'immutable_datetime',
    ];
}
