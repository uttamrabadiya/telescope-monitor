<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Services\InstallationService;
use TelescopeMonitor\Storage\Database;

final class MigrateCommand extends Command
{
    protected $signature = 'exception-monitor:migrate {--force : Run in production} {--pretend : Show SQL without applying migrations}';

    protected $description = 'Run only published monitoring migrations on the configured storage connection.';

    public function handle(InstallationService $installation): int
    {
        return Telescope::withoutRecording(fn () => $this->call('migrate', [
            '--database' => Database::connectionName(),
            '--path' => $installation->migrationPaths(),
            '--realpath' => true,
            '--force' => (bool) $this->option('force'),
            '--pretend' => (bool) $this->option('pretend'),
        ]));
    }
}
