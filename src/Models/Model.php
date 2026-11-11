<?php

namespace TelescopeMonitor\Models;

use TelescopeMonitor\Storage\Database;

abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    public function getConnectionName()
    {
        return Database::connectionName();
    }
}
