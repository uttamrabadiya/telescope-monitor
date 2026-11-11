<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\Secret;

interface SecretRepository
{
    public function find(int $installation, string $purpose): ?Secret;

    public function byKey(int $installation, string $key): ?Secret;

    public function create(int $installation, string $purpose, string $ciphertext): Secret;
}
