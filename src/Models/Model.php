<?php

namespace TelescopeMonitor\Models;

use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\LegacyBuilder;

abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    public function newEloquentBuilder($query)
    {
        return version_compare(app()->version(), '10.20.0', '>=')
            ? parent::newEloquentBuilder($query)
            : new LegacyBuilder($query);
    }

    public function getConnectionName()
    {
        return Database::connectionName();
    }
}
