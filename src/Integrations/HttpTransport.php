<?php

namespace TelescopeMonitor\Integrations;

interface HttpTransport
{
    public function request(string $method, array $destination, array $headers, string $body): HttpResult;
}
