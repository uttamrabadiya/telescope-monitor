<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Alerts\Outcome;

interface DeliveryLedger
{
    public function status(): array;

    public function routing(int $id): ?array;

    public function claim(int $id, ?int $generation, bool $actorAllowed): ?array;

    public function finish(int $id, string $token, Outcome $outcome): bool;

    public function due(int $limit): array;

    public function prune(int $limit): array;
}
