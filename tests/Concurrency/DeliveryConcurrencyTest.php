<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Alerts\Outcome;
use TelescopeMonitor\Repositories\Contracts\AlertManagement;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class DeliveryConcurrencyTest extends TestCase
{
    use CapturesExceptions;

    private function prepare(): void
    {
        $this->authorize();
        config(['telescope-monitor.alerts.allowed_environments' => ['testing']]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:00:01Z'));
        $a = app(AlertManagement::class)->command('connection.create', null, (string) Str::uuid(), ['name' => 'Canonical recipient', 'email' => 'oncall@example.test', 'enabled' => true]);
        app(AlertManagement::class)->command('rule.create', null, (string) Str::uuid(), [
            'name' => 'Shared gate',
            'enabled' => true,
            'events' => ['error.created', 'error.threshold_reached'],
            'conditions' => ['environments' => ['testing'], 'class' => null, 'origin' => null, 'tag' => null],
            'connections' => [$a['id']],
            'threshold_count' => 1,
            'window_seconds' => 300,
            'cooldown_seconds' => 1800,
        ]);
        $this->capture();
        $this->process();
    }

    private function claims(array $ids): array
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }
        $children = [];
        DB::disconnect();
        foreach ($ids as $id) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    $result = app(DeliveryLedger::class)->claim($id, 1, true);
                    exit($result ? 0 : 1);
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
        }DB::purge();
        sort($statuses);

        return $statuses;
    }

    public function test_duplicate_sender_jobs_cannot_take_a_live_claim(): void
    {
        $this->prepare();
        $id = DB::table('telescope_monitor_deliveries')->value('id');
        $this->assertSame([0, 1], $this->claims([$id, $id]));
        $this->assertDatabaseCount('telescope_monitor_delivery_attempts', 1);
        $this->assertSame(1, DB::table('telescope_monitor_deliveries')->where('status', 'processing')->count());
        $this->assertSame(1, (int) DB::table('telescope_monitor_deliveries')->value('attempt_count_total'));
    }

    public function test_different_delivery_rows_share_one_destination_reservation_and_cooldown(): void
    {
        $this->prepare();
        $this->travel(5)->minutes();
        $this->capture();
        $this->process();
        $ids = DB::table('telescope_monitor_deliveries')->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $ids);
        $this->assertSame([0, 1], $this->claims($ids));
        $active = DB::table('telescope_monitor_deliveries')->where('status', 'processing')->first();
        app(DeliveryLedger::class)->finish($active->id, $active->claim_token, Outcome::accepted());
        $this->travel(121)->seconds();
        $pending = DB::table('telescope_monitor_deliveries')->where('status', 'pending')->first();
        $this->assertNull(app(DeliveryLedger::class)->claim($pending->id, 1, true));
        $this->assertSame('cooldown', DB::table('telescope_monitor_deliveries')->where('id', $pending->id)->value('cancellation_reason'));
        $this->assertDatabaseCount('telescope_monitor_delivery_attempts', 1);
        $this->assertDatabaseCount('telescope_monitor_notification_gates', 1);
    }
}
