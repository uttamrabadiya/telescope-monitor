<?php

namespace TelescopeMonitor\Capture;

use Illuminate\Support\Facades\Bus;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Jobs\ProcessCapture;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use Throwable;

final class CaptureDispatcher
{
    public function __construct(private CaptureInboxRepository $inbox, private CaptureObservation $observation) {}

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

        return Telescope::withoutRecording(function () use ($id, $generation) {
            try {
                Bus::dispatch((new ProcessCapture($id, $generation))->onConnection(config('telescope-monitor.queues.connection'))->onQueue(config('telescope-monitor.queues.capture')));

                return true;
            } catch (Throwable) {
                $this->observation->record('capture_dispatch', 'warning', 'Queue dispatch failed. Durable capture is pending recovery.');

                return false;
            }
        });
    }

    public function recover(int $limit): int
    {
        $count = 0;
        foreach ($this->inbox->due($limit) as $id) {
            $row = $this->inbox->find($id);
            if ($row && $this->dispatch($id, $row->generation)) {
                $count++;
            }
        }

        return $count;
    }
}
