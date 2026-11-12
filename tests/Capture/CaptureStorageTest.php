<?php

namespace TelescopeMonitor\Tests\Capture;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\IncomingEntry;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\HealthService;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;
use TelescopeMonitor\Tests\TestCase;

final class CaptureStorageTest extends TestCase
{
    use CapturesExceptions;

    public function test_first_and_subsequent_host_captures_never_query_monitor_storage(): void
    {
        DB::table('exception_monitor_installations')->delete();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $first = $this->capture(import: false);
        $this->capture(import: false);
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/exception_monitor_|sqlite_(master|schema)|information_schema|pg_catalog|pragma|show\s+(tables|columns)/i', $query['query']);
        }
        $this->assertTrue(DB::table('telescope_entries')->where('uuid', $first)->exists());
        $this->assertDatabaseCount('exception_monitor_installations', 0);
        $this->assertDatabaseCount('exception_monitor_capture_inbox', 0);
        $this->assertGreaterThanOrEqual(2, $this->importSource()['buffered']);
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 2);
    }

    public function test_empty_and_non_exception_batches_do_not_touch_storage(): void
    {
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $adapter = app(StoredExceptionAdapter::class);
        $this->assertTrue($adapter->stored([], (string) Str::uuid()));
        $entry = IncomingEntry::make(['message' => 'ordinary log']);
        $entry->type = 'log';
        $entry->tags = [];
        $this->assertSame([], $adapter->tags($entry));
        $this->assertTrue($adapter->stored([$entry], (string) Str::uuid()));
        $this->assertSame([], DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
    }

    public function test_unmigrated_monitor_storage_does_not_affect_telescope_and_can_be_imported_after_repair(): void
    {
        config([
            'database.connections.unmigrated_monitor' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'exception-monitor.database.connection' => 'unmigrated_monitor',
        ]);
        try {
            $uuid = $this->capture(import: false);
            $this->assertTrue(DB::table('telescope_entries')->where('uuid', $uuid)->exists());
            $this->assertSame('missing_migrations', app(InstallationRepository::class)->storageState());
            $checks = collect(app(HealthService::class)->inspect());
            $this->assertSame('failed', $checks->firstWhere('key', 'storage')['state']);
        } finally {
            config(['exception-monitor.database.connection' => null]);
        }
        $this->importSource();
        $this->process();
        $this->assertSame($uuid, DB::table('exception_monitor_occurrences')->value('source_uuid'));
    }

    public function test_unreachable_monitor_connection_is_never_queried_by_host_capture(): void
    {
        config([
            'database.connections.unreachable_monitor' => ['driver' => 'sqlite', 'database' => '/missing-monitor-directory/database.sqlite', 'prefix' => ''],
            'exception-monitor.database.connection' => 'unreachable_monitor',
        ]);
        $attempts = [];
        DB::connection('unreachable_monitor')->beforeExecuting(function ($sql) use (&$attempts) {
            $attempts[] = $sql;
        });
        try {
            $uuid = $this->capture(import: false);
            $this->assertSame([], $attempts);
            $this->assertTrue(DB::table('telescope_entries')->where('uuid', $uuid)->exists());
        } finally {
            config(['exception-monitor.database.connection' => null]);
        }
    }

    public function test_partial_storage_failure_is_confined_to_importer_and_health_still_checks_schema(): void
    {
        app(InstallationRepository::class)->current();
        Database::schema()->drop('exception_monitor_capture_inbox');
        $uuid = $this->capture(import: false);
        $this->assertTrue(DB::table('telescope_entries')->where('uuid', $uuid)->exists());
        $this->assertFalse(app(CaptureInboxRepository::class)->ready());
        $checks = collect(app(HealthService::class)->inspect());
        $this->assertStringContainsString('migrations', $checks->firstWhere('key', 'capture')['detail']);
        $this->expectException(\TelescopeMonitor\Operations\MaintenanceFailure::class);
        $this->importSource();
    }
}
