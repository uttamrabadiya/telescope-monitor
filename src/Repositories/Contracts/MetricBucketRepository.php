<?php

namespace TelescopeMonitor\Repositories\Contracts;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Issue;

interface MetricBucketRepository
{
    public function record(Issue $issue, CarbonImmutable $time, bool $first, bool $regression, ?CarbonImmutable $previousFirst, int $days): void;

    public function prune(int $limit): int;
}
