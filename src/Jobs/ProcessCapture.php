<?php

namespace TelescopeMonitor\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CaptureProcessor;
use Throwable;

final class ProcessCapture implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $inboxId, public int $generation) {}

    public function handle(CaptureProcessor $processor): void
    {
        Telescope::withoutRecording(function () use ($processor) {
            try {
                $processor->process($this->inboxId, $this->generation);
            } catch (Throwable) {/* Durable pending/leased input remains recoverable after an infrastructure outage. */
            }
        });
    }
}
