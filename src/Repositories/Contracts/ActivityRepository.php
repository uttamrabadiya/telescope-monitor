<?php

namespace TelescopeMonitor\Repositories\Contracts;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Issue;

interface ActivityRepository
{
    public function record(Issue $issue, string $type, string $action, CarbonImmutable $time, string $mode): void;

    public function prune(int $limit): int;
}
