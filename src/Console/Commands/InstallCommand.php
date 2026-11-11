<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Services\InstallationService;

final class InstallCommand extends Command
{
    protected $signature = 'exception-monitor:install';

    protected $description = 'Publish the replacement configuration and migrations without overwriting consumer files.';

    public function handle(InstallationService $installation): int
    {
        if (! $installation->publish()) {
            $this->error('Prototype tables detected. Back up the database and use a reviewed conversion or a fresh database. Nothing was changed.');

            return self::FAILURE;
        }
        $this->info('Published missing files. Run exception-monitor:migrate and define view/operate/manage authorization gates.');

        return self::SUCCESS;
    }
}
