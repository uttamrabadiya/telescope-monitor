<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Alerts\DeliveryDispatcher;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;

final class PruneDeliveriesCommand extends Command
{
    protected $signature = 'telescope-monitor:prune-deliveries {--limit=1000}';

    protected $description = 'Prune terminal delivery history while preserving pending references.';

    public function handle(DeliveryLedger $ledger, AlertPlanning $planning, DeliveryDispatcher $dispatcher, DeliverySender $sender): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            return self::INVALID;
        }if (! $planning->ready()) {
            return self::FAILURE;
        }
        try {
            $result = Telescope::withoutRecording(function () use ($limit, $ledger) {
                return $ledger->prune($limit);
            });
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Delivery operation failed. No raw provider error was logged.');

            return self::FAILURE;
        }
    }
}
