<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Operations\MaintenanceFailure;
use TelescopeMonitor\Operations\PurgeOperations;

final class PurgeCommand extends Command
{
    protected $signature = 'telescope-monitor:purge {installation} {--confirm=} {--resume=} {--preview} {--status} {--abort} {--limit=500} {--batches=1} {--label=deployment}';

    protected $description = 'Explicitly confirm, drain, fence and resume a bounded whole-installation error-data purge.';

    public function handle(PurgeOperations $purge): int
    {
        try {
            $id = (string) $this->argument('installation');
            if ($id !== $purge->status()['installation']) {
                throw new MaintenanceFailure('installation_not_found');
            }
            if ($this->option('preview')) {
                $result = $purge->preview($id);
            } elseif ($this->option('status')) {
                $result = $purge->status($this->option('resume'));
            } else {
                $confirm = (string) $this->option('confirm');
                $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
                $batches = filter_var($this->option('batches'), FILTER_VALIDATE_INT);
                if ($limit === false || $batches === false || $limit < 1 || $limit > 10000 || $batches < 1 || $batches > 100) {
                    throw new MaintenanceFailure('invalid_batch_limit');
                }
                if ($this->option('abort')) {
                    if (! $this->option('resume')) {
                        throw new MaintenanceFailure('resume_identifier_required');
                    }$result = $purge->abort($id, $confirm, $this->option('resume'));
                } else {
                    $run = $this->option('resume') ?: $purge->begin($id, $confirm, (string) $this->option('label'))['run']['id'];
                    $result = $purge->resume($id, $confirm, $run, $limit, $batches);
                }
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            if (in_array($result['installation_state'] ?? null, ['draining', 'purging'], true)) {
                $this->warn('Maintenance is not complete. New package claims are stopped. Inspect status and resume the same run.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof MaintenanceFailure ? $e->getMessage() : 'purge_operation_failed');

            return self::FAILURE;
        }
    }
}
