<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Capture\CaptureProcessor;
use TelescopeMonitor\Operations\MaintenanceFailure;
use TelescopeMonitor\Operations\PurgeOperations;
use TelescopeMonitor\Repositories\Contracts\AlertManagement;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class OperationsConcurrencyTest extends TestCase
{
    use CapturesExceptions;

    private function prepare(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }$this->authorize();
        config(['exception-monitor.alerts.allowed_environments' => ['testing']]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:10:01Z'));
    }

    private function race(array $callbacks): array
    {
        DB::disconnect();
        $children = [];
        foreach ($callbacks as $callback) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    exit($callback() ? 0 : 1);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e::class.($e instanceof MaintenanceFailure ? ':'.$e->getMessage() : ''));
                    exit(2);
                }
            }$this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }$result = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $result[] = pcntl_wexitstatus($status);
        }DB::purge();
        sort($result);

        return $result;
    }

    private function finish(string $run): void
    {
        $i = app(InstallationRepository::class)->current();
        for ($n = 0; $n < 5; $n++) {
            $s = app(PurgeOperations::class)->resume($i->public_id, $i->public_id, $run, 2, 100);
            if ($s['run']['state'] === 'completed') {
                return;
            }
        }$this->fail('Fixture purge did not complete');
    }

    public function test_capture_ingestion_racing_the_purge_fence_cannot_resurrect_old_data(): void
    {
        $this->prepare();
        $this->capture();
        $id = DB::table('exception_monitor_capture_inbox')->value('id');
        $i = app(InstallationRepository::class)->current();
        $run = app(PurgeOperations::class)->begin($i->public_id, $i->public_id)['run']['id'];
        $results = $this->race([fn () => app(CaptureProcessor::class)->process($id, 1), fn () => app(PurgeOperations::class)->resume($i->public_id, $i->public_id, $run, 2, 1)]);
        $this->assertSame([0, 1], $results);
        $this->finish($run);
        $this->assertDatabaseCount('exception_monitor_issues', 0);
        $this->assertDatabaseCount('exception_monitor_occurrences', 0);
    }

    public function test_a_provider_claim_and_drain_serialize_without_losing_a_live_lease(): void
    {
        $this->prepare();
        $a = app(AlertManagement::class)->command('connection.create', null, (string) Str::uuid(), ['name' => 'Mail', 'email' => 'oncall@example.test', 'enabled' => true]);
        app(AlertManagement::class)->command('rule.create', null, (string) Str::uuid(), ['name' => 'New', 'enabled' => true, 'events' => ['error.created'], 'conditions' => ['environments' => ['testing']], 'connections' => [$a['id']], 'cooldown_seconds' => 0, 'threshold_count' => null, 'window_seconds' => null]);
        $this->capture();
        $this->process();
        $d = DB::table('exception_monitor_deliveries')->value('id');
        $i = app(InstallationRepository::class)->current();
        $results = $this->race([fn () => app(DeliveryLedger::class)->claim($d, 1, true), function () use ($i) {
            $r = app(PurgeOperations::class)->begin($i->public_id, $i->public_id);

            return app(PurgeOperations::class)->resume($i->public_id, $i->public_id, $r['run']['id'], 1, 1);
        }]);
        $this->assertNotContains(2, $results);
        $i = app(InstallationRepository::class)->current();
        $run = app(PurgeOperations::class)->status()['run']['id'];
        if ($i->generation === 1) {
            $this->assertSame('processing', DB::table('exception_monitor_deliveries')->value('status'));
            $this->assertSame(1, app(PurgeOperations::class)->liveLeases($i->id));
        } else {
            $this->assertSame(0, DB::table('exception_monitor_delivery_attempts')->count());
        }$this->travel(121)->seconds();
        $this->finish($run);
        $this->assertDatabaseCount('exception_monitor_deliveries', 0);
    }

    public function test_concurrent_resumers_count_each_deleted_row_once(): void
    {
        $this->prepare();
        $this->capture();
        $this->process();
        $i = app(InstallationRepository::class)->current();
        $run = app(PurgeOperations::class)->begin($i->public_id, $i->public_id)['run']['id'];
        app(PurgeOperations::class)->resume($i->public_id, $i->public_id, $run, 1, 1);
        $resume = fn () => app(PurgeOperations::class)->resume($i->public_id, $i->public_id, $run, 1, 100);
        $this->assertSame([0, 0], $this->race([$resume, $resume]));
        $status = app(PurgeOperations::class)->status($run);
        $this->assertSame('completed', $status['run']['state']);
        $this->assertSame(1, $status['run']['counts']['issues']);
        $this->assertSame(1, $status['run']['counts']['occurrences']);
        $this->assertSame(2, $status['generation']);
    }
}
