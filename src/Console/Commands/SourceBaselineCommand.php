<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Operations\MaintenanceFailure;
use TelescopeMonitor\Operations\OperationalStatus;
use TelescopeMonitor\Operations\PurgeOperations;

final class SourceBaselineCommand extends Command
{
    protected $signature = 'exception-monitor:source-baseline {installation} {--confirm=} {--preview}';

    protected $description = 'Explicitly rebaseline replaced/reset Telescope storage without silently removing time protection.';

    public function handle(PurgeOperations $purge, OperationalStatus $status): int
    {
        try {
            $id = (string) $this->argument('installation');
            if ($id !== $purge->status()['installation']) {
                throw new MaintenanceFailure('installation_not_found');
            }$result = $this->option('preview') ? [
                'installation' => $id,
                'source' => $status->source(),
                'effect' => 'Exclude existing source rows and create a new source epoch. Existing monitor error data is preserved.',
            ] : $purge->baseline($id, (string) $this->option('confirm'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof MaintenanceFailure ? $e->getMessage() : 'source_baseline_failed');

            return self::FAILURE;
        }
    }
}
