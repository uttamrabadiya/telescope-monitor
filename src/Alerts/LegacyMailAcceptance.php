<?php

namespace TelescopeMonitor\Alerts;

use Illuminate\Mail\Events\MessageSent;
use WeakMap;

final class LegacyMailAcceptance
{
    /** @var WeakMap<object, bool> */
    private WeakMap $sent;

    public function __construct()
    {
        $this->sent = new WeakMap;
    }

    public function record(MessageSent $event): void
    {
        $this->sent[$event->message] = true;
    }

    public function accepted(object $message): bool
    {
        return isset($this->sent[$message]);
    }
}
