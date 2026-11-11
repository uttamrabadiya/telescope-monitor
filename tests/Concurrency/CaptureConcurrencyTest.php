<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use TelescopeMonitor\Capture\CaptureProcessor;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class CaptureConcurrencyTest extends TestCase
{
    use CapturesExceptions;

    public function test_concurrent_first_occurrences_and_duplicate_claims_count_exactly_once(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }
        for ($i = 0; $i < 12; $i++) {
            $this->capture('Concurrent '.$i);
        }
        $ids = DB::table('exception_monitor_capture_inbox')->pluck('id')->all();
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
        $this->assertDatabaseCount('exception_monitor_issues', 1);
        $this->assertDatabaseCount('exception_monitor_occurrences', 12);
        $this->assertSame(12, (int) DB::table('exception_monitor_issues')->value('lifetime_count'));
        $this->assertSame(12, (int) DB::table('exception_monitor_metric_buckets')->where('resolution', '1h')->sum('occurrence_count'));
        $this->assertSame(1, (int) DB::table('exception_monitor_metric_buckets')->where('resolution', '1h')->sum('new_group_count'));
        $this->assertDatabaseCount('exception_monitor_alert_events', 1);
    }
}
