<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use TelescopeMonitor\Models\EvidenceTag;
use TelescopeMonitor\Models\OccurrenceEvidence;
use TelescopeMonitor\Repositories\Contracts\EvidenceTagRepository;

final class EloquentEvidenceTagRepository implements EvidenceTagRepository
{
    public function record(OccurrenceEvidence $evidence, array $tags): void
    {
        foreach ($tags as $key => $value) {
            EvidenceTag::query()->firstOrCreate([
                'evidence_id' => $evidence->id,
                'tag_hash' => hash('sha256', $key.':'.$value),
            ], [
                'installation_id' => $evidence->installation_id,
                'tag_key' => $key,
                'tag_value' => $value,
            ]);
        }
    }
}
