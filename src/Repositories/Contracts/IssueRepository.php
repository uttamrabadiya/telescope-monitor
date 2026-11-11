<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\Issue;

interface IssueRepository
{
    public function forCapture(array $data): Issue;

    public function save(Issue $issue): void;
}
