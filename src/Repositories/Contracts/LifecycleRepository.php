<?php

namespace TelescopeMonitor\Repositories\Contracts;

interface LifecycleRepository
{
    public function act(string $issue, string $actor, string $actorHash, string $clientKey, string $digest, array $data): array;

    public function expire(int $limit): int;
}
