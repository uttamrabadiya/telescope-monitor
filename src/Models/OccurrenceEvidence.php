<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property int $occurrence_id
 * @property int $installation_id
 * @property int $evidence_version
 * @property int $privacy_revision
 * @property string $payload_ciphertext
 * @property int $plaintext_bytes
 * @property string $payload_digest
 * @property string|null $route_name
 * @property string|null $path_template
 * @property string|null $job_class
 * @property string|null $command_name
 * @property string|null $release
 * @property string|null $impact_subject_hash
 * @property string|null $impact_key_id
 * @property array $coverage
 * @property CarbonImmutable $expires_at
 */
final class OccurrenceEvidence extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_occurrence_evidence';

    protected $guarded = [];

    protected $hidden = ['payload_ciphertext'];

    protected function casts(): array
    {
        return [
            'occurrence_id' => 'integer',
            'installation_id' => 'integer',
            'evidence_version' => 'integer',
            'privacy_revision' => 'integer',
            'plaintext_bytes' => 'integer',
            'coverage' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
