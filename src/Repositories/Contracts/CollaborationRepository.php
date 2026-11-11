<?php

namespace TelescopeMonitor\Repositories\Contracts;

interface CollaborationRepository
{
    public function ready(): bool;

    public function notes(string $issue, int $page): array;

    public function views(int $page = 1): array;

    public function view(string $id): array;

    public function preferences(): array;

    public function command(string $operation, ?string $id, string $clientKey, array $values): array;
}
