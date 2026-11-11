<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\Occurrence;

interface OccurrenceRepository
{
    public function record(array $data): Occurrence;

    public function findByPublicId(string $id, int $installation): ?Occurrence;
}
