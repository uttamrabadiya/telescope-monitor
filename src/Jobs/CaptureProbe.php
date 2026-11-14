<?php

namespace TelescopeMonitor\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TelescopeMonitor\Capture\CaptureProbes;

final class CaptureProbe implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
