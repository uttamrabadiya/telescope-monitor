<?php

namespace TelescopeMonitor\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CaptureProcessor;
use Throwable;

final class ProcessCapture implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
