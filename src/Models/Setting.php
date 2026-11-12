<?php

namespace TelescopeMonitor\Models;

use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property array $values
 * @property int $row_version
 */
final class Setting extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'row_version' => 'integer',
        ];
    }
}
