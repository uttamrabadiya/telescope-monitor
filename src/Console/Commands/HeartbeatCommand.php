<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Operations\OperationJournal;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;

final class HeartbeatCommand extends Command
{
    protected $signature = 'telescope-monitor:heartbeat';

    protected $description = 'Record a local maintenance heartbeat; this is not an independent availability monitor.';

    public function handle(OperationJournal $journal, RetentionPolicy $policy, InstallationRepository $installations): int
    {
        if (! $policy->ready()) {
            return self::FAILURE;
        }try {
            $result = $journal->run('heartbeat', fn () => ['generation' => $installations->current()->generation]);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('heartbeat_failed');

            return self::FAILURE;
        }
    }
}
