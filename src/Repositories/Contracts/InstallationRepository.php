<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\Installation;

interface InstallationRepository
{
    public function storageState(): string;

    public function current(): Installation;

    public function lock(): Installation;

    public function fence(): Installation;

    public function verifySource(string $signature): Installation;

    public function checkpoint(Installation $installation, int $sequence): void;

    public function advanceConfiguration(Installation $installation): void;

    public function saveSetup(Installation $installation, array $state): void;
}
