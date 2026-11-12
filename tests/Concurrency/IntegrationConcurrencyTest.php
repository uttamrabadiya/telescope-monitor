<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Integrations\GitHubIssues;
use TelescopeMonitor\Integrations\IntegrationManagement;
use TelescopeMonitor\Repositories\Contracts\AlertManagement;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class IntegrationConcurrencyTest extends TestCase
{
    use CapturesExceptions;

    private function requireEngine(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }$this->authorize();
        config(['telescope-monitor.alerts.allowed_environments' => ['testing']]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:10:01Z'));
    }

    private function fork(array $work): array
    {
        DB::disconnect();
        $children = [];
        foreach ($work as $callback) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    exit($callback() ? 0 : 1);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e::class);
                    exit(2);
                }
            }$this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }$out = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $s);
            $out[] = pcntl_wexitstatus($s);
        }DB::purge();
        sort($out);

        return $out;
    }

    public function test_concurrent_confirmations_make_one_repository_reservation_and_delivery(): void
    {
        $this->requireEngine();
        $c = app(IntegrationManagement::class)->command('connection.create', null, (string) Str::uuid(), [
            'provider' => 'github',
            'name' => 'Repository',
            'enabled' => true,
            'owner' => 'acme',
            'repo' => 'app',
            'credential' => 'controlled-token',
        ]);
        $this->capture();
        $this->process();
        $issue = DB::table('telescope_monitor_issues')->value('public_id');
        $v = ['connection' => $c['id'], 'preview_key' => (string) Str::uuid(), 'labels' => []];
        $p = app(GitHubIssues::class)->preview($issue, $v);
        $v += ['preview_digest' => $p['digest'], 'link_version' => $p['link_version']];
        $confirm = fn () => app(IntegrationManagement::class)->command('github.create', $issue, (string) Str::uuid(), $v);
        $this->assertSame([0, 0], $this->fork([$confirm, $confirm]));
        $this->assertDatabaseCount('telescope_monitor_linked_issues', 1);
        $this->assertDatabaseCount('telescope_monitor_deliveries', 1);
        $this->assertDatabaseCount('telescope_monitor_alert_events', 2);
    }

    public function test_slack_claim_reservation_is_shared_across_different_errors(): void
    {
        $this->requireEngine();
        $c = app(IntegrationManagement::class)->command('connection.create', null, (string) Str::uuid(), [
            'provider' => 'slack',
            'name' => 'Slack',
            'enabled' => true,
            'endpoint' => 'https://hooks.slack.com/services/T/B/CONTROLLED',
        ]);
        app(AlertManagement::class)->command('rule.create', null, (string) Str::uuid(), [
            'name' => 'New',
            'enabled' => true,
            'events' => ['error.created'],
            'conditions' => ['environments' => ['testing']],
            'connections' => [$c['id']],
            'threshold_count' => null,
            'window_seconds' => null,
            'cooldown_seconds' => 0,
        ]);
        $this->capture('first');
        $this->process();
        $this->capture('second', null, null, \LogicException::class);
        $this->process();
        $ids = DB::table('telescope_monitor_deliveries')->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $ids);
        $work = array_map(fn ($id) => fn () => app(DeliveryLedger::class)->claim($id, 1, true), $ids);
        $this->assertSame([0, 1], $this->fork($work));
        $this->assertSame(1, DB::table('telescope_monitor_delivery_attempts')->count());
    }
}
