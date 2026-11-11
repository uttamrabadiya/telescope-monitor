<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\CaptureInbox;

interface CaptureInboxRepository
{
    public function ready(): bool;

    public function enqueue(array $attributes): CaptureInbox;

    public function find(int $id): ?CaptureInbox;

    public function lock(int $id): ?CaptureInbox;

    public function save(CaptureInbox $item): void;

    public function due(int $limit): array;

    public function status(): array;

    public function prune(int $limit, int $days): int;
}
