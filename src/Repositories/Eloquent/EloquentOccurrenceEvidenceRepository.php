<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use TelescopeMonitor\Models\OccurrenceEvidence;
use TelescopeMonitor\Repositories\Contracts\OccurrenceEvidenceRepository;

final class EloquentOccurrenceEvidenceRepository implements OccurrenceEvidenceRepository
{
    public function record(array $data): OccurrenceEvidence
    {
        return OccurrenceEvidence::query()->create($data);
    }

    public function find(int $occurrence): ?OccurrenceEvidence
    {
        return OccurrenceEvidence::query()->where('occurrence_id', $occurrence)->first();
    }

    public function prune(int $limit): int
    {
        $ids = OccurrenceEvidence::query()->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orderBy('id')->limit($limit)->pluck('id');

        return OccurrenceEvidence::query()->whereKey($ids)->delete();
    }
}
