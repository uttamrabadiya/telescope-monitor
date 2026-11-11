<?php

namespace TelescopeMonitor\Data;

final readonly class Actor
{
    public function __construct(public string $key, public string $label) {}
}
