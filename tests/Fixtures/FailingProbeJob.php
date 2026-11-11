<?php

namespace TelescopeMonitor\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

final class FailingProbeJob implements ShouldQueue
{
    public function handle(): void
    {
        throw new RuntimeException('Terminal job contract probe');
    }
}
