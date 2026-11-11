<?php

namespace TelescopeMonitor\Alerts;

use Carbon\CarbonImmutable;

final readonly class Outcome
{
    private function __construct(public string $kind, public string $code, public ?int $status = null, public ?CarbonImmutable $retryAfter = null, public ?array $reference = null) {}

    public static function accepted(?int $status = null, ?array $reference = null): self
    {
        return new self('accepted', 'transport_accepted', $status, null, $reference);
    }

    public static function remote(string $kind, string $code, ?int $status = null, ?CarbonImmutable $at = null): self
    {
        if (! in_array($kind, ['retryable', 'permanent', 'uncertain'], true) || ! in_array($code, ['remote_throttled', 'remote_rejected', 'remote_acceptance_unknown', 'remote_temporarily_unavailable', 'destination_blocked', 'destination_unavailable'], true)) {
            throw new \InvalidArgumentException;
        }

        return new self($kind, $code, $status, $at);
    }

    public static function retryable(?CarbonImmutable $at = null): self
    {
        return new self('retryable', 'mail_temporarily_unavailable', null, $at);
    }

    public static function permanent(): self
    {
        return new self('permanent', 'mail_rejected');
    }

    public static function uncertain(): self
    {
        return new self('uncertain', 'mail_acceptance_unknown');
    }
}
