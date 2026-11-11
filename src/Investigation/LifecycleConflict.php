<?php

namespace TelescopeMonitor\Investigation;

final class LifecycleConflict extends \RuntimeException
{
    public function __construct(string $message, public array $current = [])
    {
        parent::__construct($message);
    }
}
