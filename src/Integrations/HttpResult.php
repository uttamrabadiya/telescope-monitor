<?php

namespace TelescopeMonitor\Integrations;

final readonly class HttpResult
{
    public function __construct(public int $status = 0, public string $body = '', public array $headers = []) {}

    public function json(): array
    {
        $v = json_decode($this->body, true);

        return is_array($v) ? $v : [];
    }
}
