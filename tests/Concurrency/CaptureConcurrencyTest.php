<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Capture\CaptureProcessor;
use TelescopeMonitor\Capture\HistoricalBackfill;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class CaptureConcurrencyTest extends TestCase
{
    use CapturesExceptions;

    public function test_concurrent_source_importers_deduplicate_and_preserve_checkpoint_progress(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }
        for ($n = 0; $n < 12; $n++) {
            $this->capture(import: false);
        }
        app(\TelescopeMonitor\Telescope\StoredEntries::class)->verifySource();
        DB::disconnect();
        $children = [];
        for ($n = 0; $n < 3; $n++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    for ($pass = 0; $pass < 5; $pass++) {
                        app(\TelescopeMonitor\Capture\CaptureOperations::class)->import(4);
                    }
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e::class);
                    exit(2);
                }
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge();
        $this->assertDatabaseCount('telescope_monitor_capture_inbox', 12);
        $this->assertSame((int) DB::table('telescope_entries')->max('sequence'), (int) DB::table('telescope_monitor_installations')->value('source_sequence_checkpoint'));
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_occurrences', 12);
        $this->assertSame(12, (int) DB::table('telescope_monitor_issues')->value('lifetime_count'));
    }

    public function test_concurrent_first_occurrences_and_duplicate_claims_count_exactly_once(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }
        for ($i = 0; $i < 12; $i++) {
            $this->capture('Concurrent '.$i);
        }
        $ids = DB::table('telescope_monitor_capture_inbox')->pluck('id')->all();
        DB::disconnect();
        $children = [];
        for ($worker = 0; $worker < 6; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    foreach (array_merge(array_slice($ids, $worker * 2, 2), $ids) as $id) {
                        app(CaptureProcessor::class)->process($id);
                    } exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e::class);
                    exit(2);
                }
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge();
        $this->travel(2)->minutes();
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_issues', 1);
        $this->assertDatabaseCount('telescope_monitor_occurrences', 12);
        $this->assertSame(12, (int) DB::table('telescope_monitor_issues')->value('lifetime_count'));
        $this->assertSame(12, (int) DB::table('telescope_monitor_metric_buckets')->where('resolution', '1h')->sum('occurrence_count'));
        $this->assertSame(1, (int) DB::table('telescope_monitor_metric_buckets')->where('resolution', '1h')->sum('new_group_count'));
        $this->assertDatabaseCount('telescope_monitor_alert_events', 1);
    }

    public function test_concurrent_historical_imports_count_each_source_uuid_once_without_alert_intents(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }
        for ($n = 0; $n < 12; $n++) {
            DB::table('telescope_entries')->insert([
                'uuid' => (string) Str::uuid(),
                'batch_id' => (string) Str::uuid(),
                'type' => 'exception',
                'content' => json_encode([
                    'class' => 'RuntimeException',
                    'message' => 'Historical concurrent fixture',
                    'file' => base_path('app/Services/Payment.php'),
                    'line' => 42,
                    'trace' => [],
                ]),
                'created_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
                'should_display_on_index' => true,
            ]);
        }
        DB::disconnect();
        $children = [];
        for ($worker = 0; $worker < 3; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    app(HistoricalBackfill::class)->run('testing');
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e::class);
                    exit(2);
                }
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge();
        $this->process();
        $this->assertDatabaseCount('telescope_monitor_occurrences', 12);
        $this->assertDatabaseCount('telescope_monitor_issues', 1);
        $this->assertSame(12, (int) DB::table('telescope_monitor_issues')->value('lifetime_count'));
        $this->assertSame(12, (int) DB::table('telescope_monitor_metric_buckets')->where('resolution', '1h')->sum('occurrence_count'));
        $this->assertDatabaseCount('telescope_monitor_alert_events', 0);
        $this->assertDatabaseCount('telescope_monitor_deliveries', 0);
    }
}
