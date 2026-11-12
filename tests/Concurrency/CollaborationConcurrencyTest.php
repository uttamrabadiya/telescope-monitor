<?php

namespace TelescopeMonitor\Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Repositories\Contracts\CollaborationRepository;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class CollaborationConcurrencyTest extends TestCase
{
    use CapturesExceptions;

    private function fork(array $jobs): array
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL/MariaDB and pcntl.');
        }
        $children = [];
        DB::disconnect();
        foreach ($jobs as $job) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    $job();
                    exit(0);
                } catch (Conflict) {
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
        }DB::purge();
        sort($statuses);

        return $statuses;
    }

    public function test_duplicate_note_create_commits_one_note_activity_and_disabled_intent(): void
    {
        $this->authorize();
        $this->capture();
        $this->process();
        $issue = DB::table('telescope_monitor_issues')->value('public_id');
        $key = (string) Str::uuid();
        $job = fn () => app(CollaborationRepository::class)->command('note.create', $issue, $key, ['body' => 'Concurrent investigation']);
        $this->assertSame([0, 0], $this->fork([$job, $job]));
        $this->assertDatabaseCount('telescope_monitor_notes', 1);
        $this->assertSame(1, DB::table('telescope_monitor_activity')->where('event_type', 'note.added')->count());
        $this->assertSame(1, DB::table('telescope_monitor_alert_events')->where('event_type', 'note.added')->count());
    }

    public function test_view_edits_and_preference_initialization_reject_stale_versions(): void
    {
        $this->authorize();
        $view = app(CollaborationRepository::class)->command('view.create', null, (string) Str::uuid(), ['name' => 'Concurrent', 'visibility' => 'personal', 'filters' => [], 'sort' => 'last_seen', 'range' => '24h']);
        $id = $view['id'];
        $jobs = [];
        foreach (['First', 'Second'] as $name) {
            $jobs[] = fn () => app(CollaborationRepository::class)->command('view.rename', $id, (string) Str::uuid(), ['version' => 1, 'name' => $name]);
        }$this->assertSame([0, 1], $this->fork($jobs));
        $this->assertSame(2, (int) DB::table('telescope_monitor_saved_views')->value('row_version'));
        $jobs = [];
        foreach (['builtin:resolved', 'builtin:ignored'] as $ref) {
            $jobs[] = fn () => app(CollaborationRepository::class)->command('preferences', null, (string) Str::uuid(), ['version' => 0, 'shortcuts' => [['ref' => $ref, 'favorite' => true, 'position' => 0]], 'default_view_ref' => $ref]);
        }$this->assertSame([0, 1], $this->fork($jobs));
        $this->assertDatabaseCount('telescope_monitor_view_preferences', 1);
        $this->assertSame(1, (int) DB::table('telescope_monitor_view_preferences')->value('row_version'));
    }
}
