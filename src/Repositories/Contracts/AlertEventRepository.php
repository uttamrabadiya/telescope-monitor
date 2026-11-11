<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Occurrence;

interface AlertEventRepository
{
    public function record(Issue $issue, Occurrence $occurrence, string $type, array $payload, int $generation): void;

    public function prune(int $limit): int;
}
