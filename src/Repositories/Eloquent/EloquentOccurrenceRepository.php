<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Repositories\Contracts\OccurrenceRepository;

final class EloquentOccurrenceRepository implements OccurrenceRepository
{
    public function record(array $data): Occurrence
    {
        $key = array_intersect_key($data, array_flip(['installation_id', 'source_epoch', 'source_uuid']));

        return Occurrence::query()->createOrFirst($key, array_diff_key($data, $key));
    }

    public function findByPublicId(string $id, int $installation): ?Occurrence
    {
        return Occurrence::query()->where('installation_id', $installation)->where('public_id', $id)->first();
    }
}
