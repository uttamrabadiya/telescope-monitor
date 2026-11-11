<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Models\Setting;

interface SettingRepository
{
    public function find(int $installationId, string $group): ?Setting;

    public function write(int $installationId, string $group, array $values, string $actor): Setting;
}
