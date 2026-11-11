<?php

namespace TelescopeMonitor\Repositories\Contracts;

interface HealthCheckRepository
{
    public function record(int $installationId, array $checks): void;

    public function latest(int $installationId): array;
}
