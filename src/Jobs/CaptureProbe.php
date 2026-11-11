<?php

namespace TelescopeMonitor\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use TelescopeMonitor\Capture\CaptureProbes;

final class CaptureProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $operationId, public int $generation) {}

    public function handle(CaptureProbes $probes): void
    {
        try {
            $probes->execute($this->operationId, $this->generation);
        } catch (\Throwable) {/* Pending receipt is recovered without serializing/reporting a live exception. */
        }
    }
}
