<?php

namespace TelescopeMonitor\Alerts;

interface NotificationProvider
{
    public function send(array $envelope, array $target): Outcome;
}
