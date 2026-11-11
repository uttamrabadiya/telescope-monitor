<?php

namespace TelescopeMonitor\Storage;

abstract class Migration extends \Illuminate\Database\Migrations\Migration
{
    public function getConnection()
    {
        return Database::connectionName();
    }
}
