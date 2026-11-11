<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\OccurrenceEvidence;

interface EvidenceTagRepository
{
    public function record(OccurrenceEvidence $evidence, array $tags): void;
}
