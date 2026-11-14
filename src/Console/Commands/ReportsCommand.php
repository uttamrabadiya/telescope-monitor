<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Reports\ReportScheduler;

final class ReportsCommand extends Command
{
    protected $signature = 'telescope-monitor:reports';

    protected $description = 'Prepare due daily and weekly email reports for durable delivery.';

    public function handle(AlertPlanning $planning, ReportScheduler $reports): int
    {
        if (! $planning->ready()) {
            $this->error('Monitoring alert storage is not ready.');

            return self::FAILURE;
        }
        $failed = false;
        foreach (['daily', 'weekly'] as $kind) {
            try {
                $result = $reports->queue($kind);
                $this->line($kind.': '.count($result['deliveries']).' durable deliveries'.($result['skipped'] ? ' (not due or no activity)' : ''));
            } catch (\Throwable) {
                $failed = true;
                $this->error('The '.$kind.' report could not be prepared. Review report settings and monitoring storage.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
