<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Investigation\LifecycleConflict;
use TelescopeMonitor\Repositories\Contracts\LifecycleRepository;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class LifecycleConcurrencyTest extends TestCase
{
    use CapturesExceptions;

    public function test_competing_lifecycle_actions_accept_one_version_and_duplicate_receipts_commit_once(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }
        $this->capture();
        $this->process();
        $id = DB::table('exception_monitor_issues')->value('public_id');
        $children = [];
        DB::disconnect();
        foreach (['resolve', 'ignore'] as $action) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    app(LifecycleRepository::class)->act($id, 'testing:1', hash('sha256', 'actor'), (string) Str::uuid(), hash('sha256', $action), ['version' => 1, 'action' => $action]);
                    exit(0);
                } catch (LifecycleConflict) {
                    exit(1);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e::class);
                    exit(2);
                }
            }$this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }sort($statuses);
        DB::purge();
        $this->assertSame([0, 1], $statuses);
        $this->assertSame(2, (int) DB::table('exception_monitor_issues')->value('state_version'));
        $this->assertSame(1, DB::table('exception_monitor_action_receipts')->where('operation', 'issue.lifecycle')->count());
        $key = (string) Str::uuid();
        $children = [];
        DB::disconnect();
        for ($n = 0; $n < 2; $n++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    app(LifecycleRepository::class)->act($id, 'testing:1', hash('sha256', 'actor'), $key, hash('sha256', 'reopen'), ['version' => 2, 'action' => 'reopen']);
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e::class);
                    exit(2);
                }
            }$this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }DB::purge();
        $this->assertSame(1, DB::table('exception_monitor_activity')->where('event_type', 'error.reopen')->count());
        $this->assertSame('unresolved', DB::table('exception_monitor_issues')->value('status'));
    }
}
