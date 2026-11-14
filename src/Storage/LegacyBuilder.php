<?php

namespace TelescopeMonitor\Storage;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Insert-first behavior for framework versions without native createOrFirst.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class LegacyBuilder extends Builder
{
    /** @return TModel */
    public function createOrFirst(array $attributes = [], Closure|array $values = [])
    {
        $connection = $this->getModel()->getConnection();
        $create = fn () => $this->create(array_merge($attributes, value($values)));
        try {
            // Roll back a failed insert to a savepoint before looking up the winner.
            return $connection->transactionLevel() > 0 ? $connection->transaction($create) : $create();
        } catch (QueryException $exception) {
            if (! $this->uniqueViolation($exception, $connection->getDriverName())) {
                throw $exception;
            }

            return $this->useWritePdo()->where($attributes)->first() ?? throw $exception;
        }
    }

    private function uniqueViolation(QueryException $exception, string $driver): bool
    {
        $info = $exception->errorInfo ?? [];
        $state = (string) ($info[0] ?? $exception->getCode());
        $code = (int) ($info[1] ?? 0);
        $message = (string) ($info[2] ?? '');

        return match ($driver) {
            'mysql', 'mariadb' => $state === '23000' && $code === 1062,
            'sqlite' => in_array($code, [19, 1555, 2067], true) && (str_contains($message, 'UNIQUE constraint failed:') || str_contains($message, 'is not unique')),
            'pgsql' => $state === '23505',
            'sqlsrv' => $state === '23000' && in_array($code, [2601, 2627], true),
            default => false,
        };
    }
}
