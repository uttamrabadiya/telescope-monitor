<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\ActionReceipt;

interface ActionReceiptRepository
{
    public function createProbe(int $installation, int $generation, string $actor, string $clientKey, string $digest): ActionReceipt;

    public function probe(string $id): ?ActionReceipt;

    public function update(string $id, array $data): void;

    public function pending(int $limit): array;

    public function transition(string $id, array $states, array $data): void;
}
