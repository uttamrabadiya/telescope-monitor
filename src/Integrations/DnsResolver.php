<?php

namespace TelescopeMonitor\Integrations;

interface DnsResolver
{
    public function addresses(string $host): array;
}
