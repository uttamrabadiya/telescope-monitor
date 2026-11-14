<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use TelescopeMonitor\Alerts\DeliverySender;
use TelescopeMonitor\Alerts\NotificationProvider;
use TelescopeMonitor\Alerts\Outcome;
use TelescopeMonitor\Integrations\GitHubIssues;
use TelescopeMonitor\Integrations\IntegrationManagement;
use TelescopeMonitor\Repositories\Contracts\AlertManagement;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class DeliveryCrashTest extends TestCase
{
    use CapturesExceptions;

    public static function crashPoints(): array
    {
        $cases = [];
        foreach (['email', 'github'] as $provider) {
            foreach (['before_claim', 'during_send', 'after_acceptance'] as $point) {
                $cases[$provider.' '.$point] = [$provider, $point];
            }
        }
        return $cases;
    }

    /** @dataProvider crashPoints */
    #[DataProvider('crashPoints')]
    public function test_process_death_preserves_recoverable_work_and_never_recreates_github(string $provider, string $point): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires MySQL/MariaDB, pcntl and posix.');
        }
        $this->authorize();
        config(['telescope-monitor.alerts.allowed_environments' => ['testing']]);
        if ($provider === 'email') {
            $connection = app(AlertManagement::class)->command('connection.create', null, (string) Str::uuid(), ['name' => 'Demo recipient', 'email' => 'oncall@example.test', 'enabled' => true]);
            app(AlertManagement::class)->command('rule.create', null, (string) Str::uuid(), [
                'name' => 'Demo rule',
                'enabled' => true,
                'events' => ['error.created'],
                'conditions' => ['environments' => ['testing']],
                'connections' => [$connection['id']],
                'threshold_count' => null,
                'window_seconds' => null,
                'cooldown_seconds' => 0,
            ]);
        }
        $this->capture('Synthetic process death fixture');
        $this->process();
        if ($provider === 'github') {
            $connection = app(IntegrationManagement::class)->command('connection.create', null, (string) Str::uuid(), [
                'provider' => 'github',
                'name' => 'Demo repository',
                'enabled' => true,
                'owner' => 'demo',
                'repo' => 'widgets',
                'credential' => 'synthetic-test-token',
            ]);
            $issue = DB::table('telescope_monitor_issues')->value('public_id');
            $values = ['connection' => $connection['id'], 'preview_key' => (string) Str::uuid(), 'labels' => []];
            $preview = app(GitHubIssues::class)->preview($issue, $values);
            $values += ['preview_digest' => $preview['digest'], 'link_version' => $preview['link_version']];
            app(IntegrationManagement::class)->command('github.create', $issue, (string) Str::uuid(), $values);
        }
        $delivery = DB::table('telescope_monitor_deliveries')->sole();
        $accepted = tempnam(sys_get_temp_dir(), 'tm-crash-');
        $fake = new class ($accepted, $point) implements NotificationProvider
        {
            public function __construct(private string $accepted, public string $point) {}

            public function send(array $envelope, array $target): Outcome
            {
                if ($this->point === 'during_send') {
                    posix_kill(getmypid(), SIGKILL);
                }
                // Independent synthetic provider acceptance survives the worker.
                file_put_contents($this->accepted, "accepted\n", FILE_APPEND);
                if ($this->point === 'after_acceptance') {
                    posix_kill(getmypid(), SIGKILL);
                }
                return Outcome::accepted();
            }
        };
        $this->app->instance(NotificationProvider::class, $fake);
        DB::disconnect();
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge();
                if ($point === 'before_claim') {
                    posix_kill(getmypid(), SIGKILL);
                }
                app(DeliverySender::class)->send($delivery->id, $delivery->generation);
                exit(2);
            }
            pcntl_waitpid($pid, $status);
            DB::purge();
            $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));
            $this->assertSame($point === 'before_claim' ? 'pending' : 'processing', DB::table('telescope_monitor_deliveries')->value('status'));
            $this->assertSame($point === 'after_acceptance' ? "accepted\n" : '', file_get_contents($accepted));
            if ($point !== 'before_claim') {
                $this->travel(121)->seconds();
            }
            $fake->point = 'recovery';
            // GitHub before-claim can safely send; this case tests only the pending
            // reservation, without pretending a fake response is a real issue URL.
            if ($provider === 'github' && $point === 'before_claim') {
                $this->assertDatabaseCount('telescope_monitor_delivery_attempts', 0);
                $this->assertDatabaseCount('telescope_monitor_linked_issues', 1);
                return;
            }
            app(DeliverySender::class)->send($delivery->id, $delivery->generation);
            if ($provider === 'github') {
                $this->assertSame('needs_review', DB::table('telescope_monitor_deliveries')->value('status'));
                $this->assertSame('needs_review', DB::table('telescope_monitor_linked_issues')->value('state'));
                $this->assertSame($point === 'after_acceptance' ? "accepted\n" : '', file_get_contents($accepted));
            } else {
                $this->assertSame('sent', DB::table('telescope_monitor_deliveries')->value('status'));
                $this->assertSame($point === 'after_acceptance' ? "accepted\naccepted\n" : "accepted\n", file_get_contents($accepted));
            }
            if ($point !== 'before_claim') {
                $this->assertSame('uncertain', DB::table('telescope_monitor_delivery_attempts')->orderBy('id')->value('outcome'));
            }
        } finally {
            unlink($accepted);
        }
    }
}
