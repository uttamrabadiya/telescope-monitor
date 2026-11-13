<?php

namespace TelescopeMonitor\Integrations;

/** A bounded HTTP response accumulator; rejects rather than returning partial JSON. */
final class ResponseBuffer
{
    public string $body = '';

    public bool $exceeded = false;

    public function __construct(private int $limit) {}

    public function append(string $chunk): int
    {
        if ($this->exceeded || strlen($this->body) + strlen($chunk) > $this->limit) {
            $this->exceeded = true;
            $this->body = '';

            return 0;
        }
        $this->body .= $chunk;

        return strlen($chunk);
    }
}
