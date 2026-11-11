<?php

namespace TelescopeMonitor\Integrations;

use TelescopeMonitor\Alerts\EventCatalog;
use TelescopeMonitor\Models\Connection;

final class ConnectionEvents
{
    public static function allows(Connection $c, string $event): bool
    {
        return $c->provider !== 'webhook' || in_array(EventCatalog::canonical($event), $c->options['events'] ?? array_keys(EventCatalog::LABELS), true);
    }
}
