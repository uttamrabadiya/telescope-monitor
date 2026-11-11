<?php

namespace TelescopeMonitor\Tests\Capture;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Storage\DatabaseEntriesRepository;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CaptureFailure;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Capture\Sanitizer;
use TelescopeMonitor\Tests\TestCase;

final class BoundsTest extends TestCase
{
    use CapturesExceptions;

    public function test_reconciliation_advances_oldest_first_with_bounded_overlap_and_finds_gap(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->capture('Gap '.$i);
        }
        DB::table('exception_monitor_capture_inbox')->delete();
        for ($i = 0; $i < 5; $i++) {
            app(CaptureOperations::class)->reconcile(2);
        }
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 7);
        $this->assertSame((int) DB::table('telescope_entries')->where('type', 'exception')->max('sequence'), (int) DB::table('exception_monitor_installations')->value('source_sequence_checkpoint'));
        DB::table('exception_monitor_capture_inbox')->delete();
        for ($i = 0; $i < 5; $i++) {
            app(CaptureOperations::class)->reconcile(2);
        }
        $this->process();
        $this->assertSame(7, (int) DB::table('exception_monitor_issues')->value('lifetime_count'));
    }

    public function test_pruned_batch_reference_is_visible_and_not_invented_as_success(): void
    {
        config(['exception-monitor.capture.inline_limit' => 1]);
        for ($i = 0; $i < 2; $i++) {
            $e = new \RuntimeException('Burst');
            Telescope::recordException(IncomingExceptionEntry::make($e, ['class' => $e::class, 'message' => 'Burst', 'file' => __FILE__, 'line' => 1, 'trace' => []]));
        }
        Telescope::store(app(EntriesRepository::class));
        DB::table('telescope_entries')->delete();
        $this->process();
        $this->assertSame('source_batch_unavailable', DB::table('exception_monitor_capture_inbox')->where('kind', 'batch_reference')->value('last_error_code'));
        $this->assertDatabaseCount('exception_monitor_occurrences', 1);
    }

    public function test_source_pruned_after_a_reference_page_is_reported_as_incomplete(): void
    {
        config(['exception-monitor.capture.inline_limit' => 1]);
        for ($i = 0; $i < 105; $i++) {
            $e = new \RuntimeException('Paged burst');
            Telescope::recordException(IncomingExceptionEntry::make($e, ['class' => $e::class, 'message' => 'Paged burst', 'file' => __FILE__, 'line' => 1, 'trace' => []]));
        }
        Telescope::store(app(EntriesRepository::class));
        $this->process();
        DB::table('telescope_entries')->delete();
        $this->process();
        $this->assertSame('source_batch_incomplete', DB::table('exception_monitor_capture_inbox')->where('kind', 'batch_reference')->value('last_error_code'));
        $this->assertDatabaseCount('exception_monitor_occurrences', 100);
    }

    public function test_malformed_nested_evidence_is_terminal_without_any_domain_writes(): void
    {
        $this->capture();
        $row = DB::table('exception_monitor_capture_inbox')->first();
        $data = json_decode(Crypt::decryptString($row->payload_ciphertext), true);
        $data['exception']['trace'] = [['line' => ['password' => 'bad']]];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        DB::table('exception_monitor_capture_inbox')->where('id', $row->id)->update(['payload_ciphertext' => Crypt::encryptString($json), 'payload_digest' => hash('sha256', $json)]);
        $this->process();
        $this->assertSame('malformed_trace', DB::table('exception_monitor_capture_inbox')->value('last_error_code'));
        $this->assertDatabaseCount('exception_monitor_issues', 0);
    }

    public function test_large_evidence_is_bounded_with_explicit_truncation_and_raw_values_excluded(): void
    {
        $sanitizer = app(Sanitizer::class);
        $policy = app(CapturePolicy::class)->read();
        $context = $sanitizer->batch(array_fill(0, 100, ['type' => 'log', 'content' => ['level' => 'info', 'message' => str_repeat('large ', 1000), 'context' => ['secret' => 'NEVER-CONTEXT']]]), $policy);
        $marker = ['policy' => $policy];
        $trace = array_fill(0, 100, ['file' => str_repeat('x', 4000), 'class' => str_repeat('y', 600), 'function' => str_repeat('z', 200), 'args' => ['NEVER-ARG']]);
        $data = $sanitizer->envelope(['class' => 'RuntimeException', 'message' => str_repeat('x', 30000), 'file' => '/test.php', 'line' => 1], $marker, $context, [], $trace);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(131072, strlen($json));
        $this->assertSame('unknown', $data['origin']);
        $this->assertSame('truncated', $data['coverage']['trace']);
        $this->assertSame('truncated', $data['coverage']['message']);
        $this->assertStringNotContainsString('NEVER-', $json);
        $query = $sanitizer->batch([['type' => 'query', 'content' => ['sql' => 'select secret', 'bindings' => ['NEVER-BINDING'], 'connection' => 'mysql', 'time' => 1]]], $policy);
        $this->assertStringNotContainsString('NEVER-', json_encode($query));
        $this->assertStringNotContainsString('select secret', json_encode($query));
    }

    public function test_explicit_override_keeps_package_data_on_the_host_with_a_separate_telescope_source(): void
    {
        $original = config('telescope.storage.database.connection');
        $override = config('exception-monitor.database.connection');
        config(['exception-monitor.database.connection' => config('database.default')]);
        config(['database.connections.capture_source' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'telescope.storage.database.connection' => 'capture_source']);
        $migration = require __DIR__.'/../../vendor/laravel/telescope/database/migrations/2018_08_08_100000_create_telescope_entries_table.php';
        $migration->up();
        $this->app->instance(EntriesRepository::class, new DatabaseEntriesRepository('capture_source'));
        try {
            $this->capture();
            $this->process();
            $this->assertSame(1, DB::connection('capture_source')->table('telescope_entries')->where('type', 'exception')->count());
            $this->assertDatabaseCount('exception_monitor_occurrences', 1);
            $this->assertFalse(Schema::connection('capture_source')->hasTable('exception_monitor_occurrences'));
        } finally {
            $migration->down();
            config(['telescope.storage.database.connection' => $original, 'exception-monitor.database.connection' => $override]);
        }
    }

    public function test_source_connection_change_is_fenced_without_rotating_identity(): void
    {
        $this->capture();
        DB::table('exception_monitor_installations')->update(['source_signature' => str_repeat('a', 64)]);
        $this->expectException(CaptureFailure::class);
        $this->expectExceptionMessage('source_epoch_review_required');
        app(CaptureOperations::class)->reconcile(100);
    }

    public function test_source_byte_budget_advances_in_pages_without_quarantining_later_valid_rows(): void
    {
        config(['exception-monitor.capture.max_source_bytes' => 131072]);
        for ($i = 0; $i < 3; $i++) {
            $this->capture(str_repeat('large ', 16000));
        }
        DB::table('exception_monitor_capture_inbox')->delete();
        for ($i = 0; $i < 3; $i++) {
            app(CaptureOperations::class)->reconcile(100);
        }
        $this->process();
        $this->assertDatabaseCount('exception_monitor_occurrences', 3);
        $this->assertSame(0, DB::table('exception_monitor_capture_inbox')->where('state', 'failed')->count());
    }

    public function test_source_sequence_rollback_requires_explicit_epoch_review(): void
    {
        $this->capture();
        app(CaptureOperations::class)->reconcile(100);
        DB::table('telescope_entries')->delete();
        $this->expectException(CaptureFailure::class);
        $this->expectExceptionMessage('source_epoch_review_required');
        app(CaptureOperations::class)->reconcile(100);
    }
}
