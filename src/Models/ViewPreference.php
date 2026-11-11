<?php

namespace TelescopeMonitor\Models;

use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property int $installation_id
 * @property string $actor_key
 * @property string $actor_lookup_hash
 * @property array $shortcuts
 * @property string|null $default_view_ref
 * @property int $row_version
 * @property int $preferences_version
 */
final class ViewPreference extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'exception_monitor_view_preferences';

    protected $guarded = [];

    protected $hidden = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['installation_id' => 'integer', 'shortcuts' => 'array', 'preferences_version' => 'integer', 'row_version' => 'integer'];
    }
}
