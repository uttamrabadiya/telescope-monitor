<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Alerts\DeliveryDispatcher;
use TelescopeMonitor\Capture\CaptureObservation;
use TelescopeMonitor\Operations\OperationJournal;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;

final class RecoverDeliveriesCommand extends Command
{
    protected $signature = 'telescope-monitor:recover-deliveries {--limit=100}';

    protected $description = 'Redispatch eligible durable email work.';

    public function handle(DeliveryLedger $ledger, AlertPlanning $planning, DeliveryDispatcher $dispatcher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            return self::INVALID;
        }if (! $planning->ready()) {
            return self::FAILURE;
        }
        try {
            $result = app(OperationJournal::class)->run('delivery_recovery', function () use ($limit, $ledger, $dispatcher) {
                $count = 0;
                $failed = 0;
                foreach ($ledger->due($limit) as $row) {
                    if ($dispatcher->dispatch($row['id'], $row['generation'])) {
                        $count++;
                    } else {
                        $failed++;
                    }
                }app(CaptureObservation::class)->record('delivery_recovery', $dispatcher->asynchronous() && $failed === 0 ? 'passed' : 'warning', ! $dispatcher->asynchronous() ? 'No supported asynchronous queue is configured; deliveries remain durable.' : ($failed ? 'Some durable deliveries could not be dispatched; a later sweep can recover them.' : 'Recovery sweep dispatched eligible durable deliveries.'));

                return ['dispatched' => $count];
            });
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Delivery operation failed. No raw provider error was logged.');

            return self::FAILURE;
        }
    }
}
