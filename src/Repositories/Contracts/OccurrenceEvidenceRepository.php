<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\OccurrenceEvidence;

interface OccurrenceEvidenceRepository
{
    public function record(array $data): OccurrenceEvidence;

    public function find(int $occurrence): ?OccurrenceEvidence;

    public function prune(int $limit): int;
}
