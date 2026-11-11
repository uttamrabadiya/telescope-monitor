<?php

namespace TelescopeMonitor\Collaboration;

final class Conflict extends \RuntimeException
{
    public function __construct(string $message, public array $current = [])
    {
        parent::__construct($message);
    }
}
