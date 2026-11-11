<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Services\HealthService;

final class DiagnoseCommand extends Command
{
    protected $signature = 'exception-monitor:diagnose {--json}';

    protected $description = 'Inspect actual configuration and observed readiness without claiming unverified processing health.';

    public function handle(HealthService $health): int
    {
        $checks = $health->inspect();
        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check) {
                $this->line(strtoupper($check['state']).' '.$check['label'].' — '.$check['detail']);
            }
        }

        return collect($checks)->every(fn ($check) => $check['state'] === 'passed') ? self::SUCCESS : self::FAILURE;
    }
}
