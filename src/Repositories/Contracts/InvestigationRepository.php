<?php

namespace TelescopeMonitor\Repositories\Contracts;

use TelescopeMonitor\Investigation\Window;
use TelescopeMonitor\Models\Issue;

interface InvestigationRepository
{
    public function inbox(int $installation, array $filters, Window $window): array;

    public function overview(int $installation, array $filters, Window $window): array;

    public function issue(int $installation, string $id, bool $lock = false): ?Issue;

    public function detail(int $installation, string $id, array $filters, Window $window): ?array;
}
