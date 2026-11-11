<?php

namespace TelescopeMonitor\Foundation;

final class PackagePath
{
    public function prefix(): string
    {
        return trim((string) config('exception-monitor.path', 'exception-monitor'), '/');
    }

    public function valid(): bool
    {
        return preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $this->prefix()) === 1;
    }

    public function base(): string
    {
        return '/'.$this->prefix();
    }
}
