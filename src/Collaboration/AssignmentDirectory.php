<?php

namespace TelescopeMonitor\Collaboration;

use TelescopeMonitor\Data\Actor;

interface AssignmentDirectory
{
    /** Return at most $limit eligible host actors; never enumerate without a bound. @return list<Actor> */
    public function search(string $query, int $limit): array;

    /** Return null for removed or ineligible actors. */
    public function find(string $key): ?Actor;
}
