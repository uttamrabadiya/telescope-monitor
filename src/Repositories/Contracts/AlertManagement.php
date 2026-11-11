<?php

namespace TelescopeMonitor\Repositories\Contracts;

interface AlertManagement
{
    public function configuration(): array;

    public function history(array $filters): array;

    public function detail(string $id): array;

    public function preview(array $definition, ?string $issue): array;

    public function reviewPreview(string $id): array;

    public function command(string $operation, ?string $id, string $clientKey, array $values): array;

    public function operation(string $id): array;
}
