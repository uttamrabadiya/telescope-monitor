<?php

namespace TelescopeMonitor\Models;

use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property string $owner_actor_key
 * @property string $owner_lookup_hash
 * @property string $name
 * @property string $visibility
 * @property array $filters
 * @property array $relative_range
 * @property string $sort_key
 * @property int $row_version
 * @property int $filter_schema_version
 */
final class SavedView extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'exception_monitor_saved_views';

    protected $guarded = [];

    protected $attributes = ['row_version' => 1, 'filter_schema_version' => 1];

    protected $hidden = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['installation_id' => 'integer', 'filters' => 'array', 'relative_range' => 'array', 'row_version' => 'integer', 'filter_schema_version' => 'integer'];
    }
}
