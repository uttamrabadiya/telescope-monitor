<?php

namespace Workbench\App\Providers;

use TelescopeMonitor\Collaboration\AssignmentDirectory;
use TelescopeMonitor\Data\Actor;

/** Local-only, explicitly enabled UI fixture; never discovered in consumers. */
final class WorkbenchAssignmentDirectory implements AssignmentDirectory
{
    public function search(string $query, int $limit): array
    {
        if (! app()->environment('local', 'workbench')) {
            return [];
        }

        return array_slice(array_values(array_filter([
            new Actor('workbench:developer', 'Alex Developer'),
            new Actor('workbench:casey', 'Casey Operator'),
            new Actor('workbench:sam', 'Sam Developer'),
        ], fn ($a) => $query === '' || str_contains(strtolower($a->label), strtolower($query)))), 0, min(25, $limit));
    }

    public function find(string $key): ?Actor
    {
        foreach ($this->search('', 25) as $actor) {
            if ($actor->key === $key) {
                return $actor;
            }
        }

        return null;
    }
}
