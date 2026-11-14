<?php

namespace TelescopeMonitor\Storage;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class Database
{
    public static function connectionName(): string
    {
        $name = config('telescope-monitor.database.connection')
            ?? config('telescope.storage.database.connection')
            ?? config('database.default');
        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Configure a valid monitoring, Telescope or Laravel default database connection.');
        }

        return $name;
    }

    public static function connection(): Connection
    {
        return DB::connection(self::connectionName());
    }

    public static function schema(): Builder
    {
        return self::connection()->getSchemaBuilder();
    }

    public static function rollbackSchema(Closure $rollback): void
    {
        $connection = self::connection();
        // Older SQLite grammars rebuild tables to remove columns. Referencing
        // tables must not prevent that rebuild during an explicit rollback.
        $restore = $connection->getDriverName() === 'sqlite'
            && ($connection->selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0);
        if ($restore) {
            self::schema()->disableForeignKeyConstraints();
        }
        try {
            $rollback();
        } finally {
            if ($restore) {
                self::schema()->enableForeignKeyConstraints();
            }
        }
    }
}
