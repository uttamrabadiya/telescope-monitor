<?php

namespace TelescopeMonitor\Models;

use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property int $evidence_id
 * @property int $installation_id
 * @property string $tag_key
 * @property string|null $tag_value
 * @property string $tag_hash
 */
final class EvidenceTag extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_evidence_tags';

    protected $guarded = [];

    protected $casts = [
        'evidence_id' => 'integer',
        'installation_id' => 'integer',
    ];
}
