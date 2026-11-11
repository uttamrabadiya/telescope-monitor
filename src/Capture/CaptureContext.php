<?php

namespace TelescopeMonitor\Capture;

use Throwable;

final class CaptureContext
{
    public ?array $job = null;

    public ?string $command = null;

    public ?Throwable $failedJobException = null;

    public ?array $failedJobOrigin = null;

    public ?Throwable $probeException = null;

    public ?string $probeId = null;
}
