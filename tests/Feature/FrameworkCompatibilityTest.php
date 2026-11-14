<?php

namespace TelescopeMonitor\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use TelescopeMonitor\Models\Installation;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Storage\LegacyBuilder;
use TelescopeMonitor\Tests\TestCase;

final class FrameworkCompatibilityTest extends TestCase
{
    private function legacy(): LegacyBuilder
    {
        $model = new Installation;

        return (new LegacyBuilder($model->newQuery()->getQuery()))->setModel($model);
    }

    private function values(): array
    {
        return ['public_id' => (string) Str::uuid(), 'source_epoch' => (string) Str::uuid(), 'setup_state' => ['checked' => true]];
    }

    public function test_model_casts_and_insert_first_duplicates_work_inside_a_transaction(): void
    {
        Database::connection()->transaction(function () {
            $first = $this->legacy()->createOrFirst(['singleton_key' => 'compat'], $this->values());
            $second = $this->legacy()->createOrFirst(['singleton_key' => 'compat'], $this->values());

            $this->assertTrue($first->wasRecentlyCreated);
            $this->assertFalse($second->wasRecentlyCreated);
            $this->assertSame($first->id, $second->id);
            $this->assertSame(['checked' => true], $second->setup_state);
            $this->assertIsInt($second->generation);
            $this->assertSame(1, Installation::query()->where('singleton_key', 'compat')->count());
            $this->assertSame(1, Database::connection()->transactionLevel());
        });
    }

    public function test_insert_first_does_not_swallow_non_unique_database_errors(): void
    {
        $this->expectException(QueryException::class);
        // source_epoch is required; this is not a duplicate-key conflict.
        $this->legacy()->createOrFirst(['singleton_key' => 'invalid'], ['public_id' => (string) Str::uuid()]);
    }

    public function test_insert_first_rethrows_conflicts_without_a_matching_record(): void
    {
        $values = $this->values();
        $this->legacy()->createOrFirst(['singleton_key' => 'first'], $values);
        $this->expectException(QueryException::class);
        $this->legacy()->createOrFirst(['singleton_key' => 'different'], $values);
    }
}
