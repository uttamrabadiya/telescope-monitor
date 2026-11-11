<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Occurrence;

interface AlertPlanning
{
    public function ready(): bool;

    public function event(AlertEvent $event, Issue $issue): void;

    public function occurrence(Issue $issue, Occurrence $occurrence): void;

    public function activity(Issue $issue, string $type, string $action, ?string $actor, array $metadata = []): void;

    public function cancelOccurrence(Issue $issue): void;
}
