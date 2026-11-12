<?php

namespace TelescopeMonitor\Alerts;

use Illuminate\Support\Facades\Bus;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CaptureObservation;
use TelescopeMonitor\Jobs\SendDelivery;

final class DeliveryDispatcher
{
    public function asynchronous(): bool
    {
        $connection = config('telescope-monitor.queues.connection') ?? config('queue.default');

        return in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true);
    }

    public function dispatch(int $id, int $generation): bool
    {
        if (! $this->asynchronous()) {
            return false;
        }
        try {
            Telescope::withoutRecording(fn () => Bus::dispatch((new SendDelivery($id, $generation))->onConnection(config('telescope-monitor.queues.connection'))->onQueue(config('telescope-monitor.queues.delivery'))));

            return true;
        } catch (\Throwable) {
            app(CaptureObservation::class)->record('delivery_dispatch', 'warning', 'Delivery dispatch failed; durable intent remains recoverable.');

            return false;
        }
    }
}
