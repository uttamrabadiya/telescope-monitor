<?php

namespace TelescopeMonitor\Tests\Investigation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\RequestWatcher;
use TelescopeMonitor\Capture\SourceSnapshot;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class InvestigationTest extends TestCase
{
    use CapturesExceptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:42:00Z'));
    }

    private function id(): string
    {
        return DB::table('exception_monitor_issues')->orderByDesc('id')->value('public_id');
    }

    private function action(string $action, int $version = 1, ?string $key = null, ?string $until = null)
    {
        return $this->postJson('/exception-monitor/api/v1/issues/'.$this->id().'/actions', array_filter(['action' => $action, 'version' => $version, 'client_key' => $key ?? (string) Str::uuid(), 'until' => $until], fn ($v) => $v !== null));
    }

    public function test_overview_uses_real_event_time_buckets_and_excludes_tests_by_default(): void
    {
        $this->capture(at: CarbonImmutable::now('UTC')->subHours(2));
        $this->capture();
        $this->process();
        $r = $this->getJson('/exception-monitor/api/v1/overview')->assertOk()->assertJsonPath('exception_monitor_private.totals.occurrences', '2')->assertJsonPath('exception_monitor_private.totals.new_groups', '1');
        $this->assertCount(24, $r->json('exception_monitor_private.buckets'));
        $this->assertSame(2, array_sum(array_column($r->json('exception_monitor_private.buckets'), 'occurrences')));
        DB::table('exception_monitor_issues')->update(['is_test' => true]);
        $this->getJson('/exception-monitor/api/v1/overview')->assertJsonPath('exception_monitor_private.totals.occurrences', '0');
        $this->getJson('/exception-monitor/api/v1/overview?include_tests=1')->assertJsonPath('exception_monitor_private.totals.occurrences', '2');
    }

    public function test_count_range_keeps_older_unresolved_groups_visible_and_explicit_dates_filter_them(): void
    {
        $this->capture('Older failure', at: CarbonImmutable::now('UTC')->subDays(3));
        $this->process();
        $this->getJson('/exception-monitor/api/v1/issues?range=1h')->assertOk()->assertJsonPath('exception_monitor_private.total', '1')->assertJsonPath('exception_monitor_private.items.0.range_count', '0')->assertJsonPath('exception_monitor_private.items.0.lifetime_count', '1');
        $this->getJson('/exception-monitor/api/v1/issues?last_after=2026-11-10')->assertJsonPath('exception_monitor_private.total', '0');
    }

    public function test_literal_search_validation_and_deterministic_pagination(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->capture('Failure '.$i, class: 'Fixture'.$i);
        }$this->capture('Literal 100%_done', class: 'Literal');
        $this->process();
        $first = $this->getJson('/exception-monitor/api/v1/issues')->assertOk();
        $this->assertCount(25, $first->json('exception_monitor_private.items'));
        $second = $this->getJson('/exception-monitor/api/v1/issues?page=2')->assertOk();
        $this->assertCount(2, $second->json('exception_monitor_private.items'));
        $this->assertEmpty(array_intersect(array_column($first->json('exception_monitor_private.items'), 'id'), array_column($second->json('exception_monitor_private.items'), 'id')));
        $this->getJson('/exception-monitor/api/v1/issues?search='.urlencode('%_'))->assertJsonPath('exception_monitor_private.total', '1');
        foreach (['range=year', 'sort=sql', 'per_page=500', 'assignee=1', 'tag=secret:value'] as $f) {
            $this->getJson('/exception-monitor/api/v1/issues?'.$f)->assertUnprocessable();
        }
    }

    public function test_retained_details_survive_source_pruning_and_selected_occurrence_is_scoped(): void
    {
        $a = $this->capture('First');
        $this->capture('Second');
        $this->process();
        $id = $this->id();
        $occ = DB::table('exception_monitor_occurrences')->where('source_uuid', $a)->value('public_id');
        DB::table('telescope_entries')->delete();
        $this->getJson('/exception-monitor/api/v1/issues/'.$id.'?occurrence='.$occ)->assertOk()->assertJsonPath('exception_monitor_private.selected.evidence.exception.message', 'First')->assertJsonPath('exception_monitor_private.issue.lifetime_count', '2');
        $this->capture('Other', class: 'OtherFixture');
        $this->process();
        $this->getJson('/exception-monitor/api/v1/issues/'.$this->id().'?occurrence='.$occ)->assertNotFound();
        $this->travel(31)->days();
        $this->getJson('/exception-monitor/api/v1/issues/'.$id.'?occurrence='.$occ)->assertOk()->assertJsonPath('exception_monitor_private.selected.evidence_state', 'expired')->assertJsonPath('exception_monitor_private.selected.evidence', null);
    }

    public function test_lifecycle_receipt_deduplication_digest_conflict_and_permissions(): void
    {
        $this->capture();
        $this->process();
        $key = (string) Str::uuid();
        $this->action('resolve', 1, $key)->assertOk();
        $this->action('resolve', 1, $key)->assertOk()->assertJsonPath('exception_monitor_private.replayed', true);
        $this->action('ignore', 1, $key)->assertConflict();
        $this->action('reopen', 1)->assertConflict();
        $this->assertSame(1, DB::table('exception_monitor_activity')->where('event_type', 'error.resolve')->count());
        $this->action('reopen', 2)->assertOk();
        $this->action('ignore', 3)->assertOk();
        $this->authorize(operate: false);
        $this->action('reopen', 4)->assertForbidden();
        $this->getJson('/exception-monitor/api/v1/issues/'.$this->id())->assertOk();
        Gate::define('viewExceptionMonitor', fn () => false);
        $this->getJson('/exception-monitor/api/v1/issues')->assertForbidden();
    }

    public function test_count_only_updates_do_not_invalidate_action_and_late_capture_does_not_regress(): void
    {
        $this->capture();
        $this->process();
        $this->capture();
        $this->process();
        $this->action('resolve')->assertOk();
        $this->capture(at: CarbonImmutable::now('UTC')->subMinute());
        $this->process();
        $this->assertSame('resolved', DB::table('exception_monitor_issues')->value('status'));
        $this->capture(at: CarbonImmutable::now('UTC'));
        $this->process();
        $this->assertSame('resolved', DB::table('exception_monitor_issues')->value('status'));
        $this->travel(1)->seconds();
        $this->capture();
        $this->process();
        $this->assertSame('unresolved', DB::table('exception_monitor_issues')->value('status'));
        $this->assertSame(1, DB::table('exception_monitor_alert_events')->where('event_type', 'error.regressed')->count());
    }

    public function test_snooze_expiry_is_visible_without_scheduler_and_action_is_atomic_with_expiry(): void
    {
        $this->capture();
        $this->process();
        $until = CarbonImmutable::now('UTC')->addHour()->toISOString();
        $this->action('snooze', 1, until: $until)->assertOk();
        $this->assertSame('cancelled', DB::table('exception_monitor_alert_events')->value('planning_state'));
        $this->travel(2)->hours();
        $this->getJson('/exception-monitor/api/v1/issues')->assertOk()->assertJsonPath('exception_monitor_private.items.0.status', 'unresolved');
        $this->action('resolve', 2)->assertOk();
        $this->assertSame('resolved', DB::table('exception_monitor_issues')->value('status'));
        $this->assertSame(1, DB::table('exception_monitor_activity')->where('event_type', 'error.snooze_expired')->count());
        $this->assertSame(4, (int) DB::table('exception_monitor_issues')->value('state_version'));
    }

    public function test_early_unsnooze_raises_cutoff_at_action_not_future_deadline_and_maintenance_is_bounded(): void
    {
        $this->capture();
        $this->process();
        $this->action('snooze', 1, until: CarbonImmutable::now('UTC')->addDays(7)->toISOString())->assertOk();
        $this->travel(1)->hours();
        $this->action('unsnooze', 2)->assertOk();
        $this->assertSame(CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'), DB::table('exception_monitor_issues')->value('notification_cutoff_at'));
        $this->action('snooze', 3, until: CarbonImmutable::now('UTC')->addHour()->toISOString())->assertOk();
        $this->travel(2)->hours();
        $this->artisan('exception-monitor:expire-snoozes', ['--limit' => 1])->assertSuccessful();
        $this->artisan('exception-monitor:expire-snoozes', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('exception_monitor_activity')->where('event_type', 'error.snooze_expired')->count());
    }

    public function test_immutable_source_mapping_is_used_instead_of_current_deployment(): void
    {
        config([
            'exception-monitor.capture.release' => 'abc123',
            'exception-monitor.capture.source_link_template' => 'https://github.com/example/app/blob/{release}/{file}#L{line}',
        ]);
        $this->capture();
        $this->process();
        config([
            'exception-monitor.capture.release' => 'changed',
            'exception-monitor.capture.source_link_template' => 'https://other.test/{release}/{file}',
        ]);
        $r = $this->getJson('/exception-monitor/api/v1/issues/'.$this->id())->assertOk();
        $this->assertSame('https://github.com/example/app/blob/abc123/app/Services/Payment.php#L42', $r->json('exception_monitor_private.selected.source_url'));
        $this->assertSame('abc123', $r->json('exception_monitor_private.occurrences.items.0.release'));
    }

    public function test_trends_survive_snapshot_retention_and_do_not_invent_route_or_user_insights(): void
    {
        $this->capture();
        $this->process();
        DB::table('exception_monitor_occurrence_evidence')->delete();
        $this->getJson('/exception-monitor/api/v1/overview')->assertOk()->assertJsonPath('exception_monitor_private.totals.occurrences', '1');
        $this->getJson('/exception-monitor/api/v1/issues/'.$this->id())->assertOk()->assertJsonPath('exception_monitor_private.insights.retained_count', '0')->assertJsonPath('exception_monitor_private.insights.breakdowns.route', [])->assertJsonPath('exception_monitor_private.insights.breakdowns.users', 0);
    }

    public function test_lifecycle_failure_rolls_back_state_receipt_and_activity(): void
    {
        $this->capture();
        $this->process();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'exception_monitor_activity')) {
                throw new \RuntimeException('Simulated storage failure');
            }
        });
        $this->action('resolve')->assertStatus(503);
        $this->assertSame('unresolved', DB::table('exception_monitor_issues')->value('status'));
        $this->assertSame(1, (int) DB::table('exception_monitor_issues')->value('state_version'));
        $this->assertSame(0, DB::table('exception_monitor_action_receipts')->where('operation', 'issue.lifecycle')->count());
        $this->assertSame(0, DB::table('exception_monitor_activity')->where('event_type', 'error.resolve')->count());
    }

    public function test_trend_ranges_have_expected_bucket_shapes_and_environment_scope(): void
    {
        $this->capture();
        $this->process();
        foreach (['1h' => 12, '24h' => 24, '7d' => 28, '30d' => 30] as $range => $count) {
            $r = $this->getJson('/exception-monitor/api/v1/overview?range='.$range)->assertOk();
            $this->assertCount($count, $r->json('exception_monitor_private.buckets'));
            $this->assertSame('1', $r->json('exception_monitor_private.totals.occurrences'));
        }
        $this->getJson('/exception-monitor/api/v1/overview?environment=other')->assertJsonPath('exception_monitor_private.totals.occurrences', '0');
    }

    public function test_filtered_origin_and_tags_use_retained_metadata_without_fabricated_counts(): void
    {
        config(['exception-monitor.capture.allowed_tags' => ['team']]);
        Telescope::tag(fn () => ['team:payments']);
        $this->capture();
        $this->process();
        $this->getJson('/exception-monitor/api/v1/issues?tag=team:payments')->assertOk()->assertJsonPath('exception_monitor_private.total', '1');
        $this->getJson('/exception-monitor/api/v1/issues?origin=web')->assertJsonPath('exception_monitor_private.total', '0');
        $this->getJson('/exception-monitor/api/v1/issues?origin=unknown')->assertJsonPath('exception_monitor_private.total', '1');
        $this->getJson('/exception-monitor/api/v1/issues/'.$this->id())->assertJsonPath('exception_monitor_private.insights.breakdowns.tags.0.value', 'team:payments');
        DB::table('exception_monitor_occurrence_evidence')->delete();
        $this->getJson('/exception-monitor/api/v1/issues?tag=team:payments')->assertJsonPath('exception_monitor_private.total', '0');
    }

    public function test_explicit_custom_snooze_offset_is_normalized_and_invalid_times_are_rejected(): void
    {
        $this->capture();
        $this->process();
        $this->action('snooze', until: '2026-11-11T09:00:00Z')->assertUnprocessable();
        $this->action('snooze', until: '2026-11-12T12:00:00')->assertUnprocessable();
        $this->action('snooze', until: '2026-11-12T12:00:00+05:30')->assertOk();
        $this->assertSame('2026-11-12 06:30:00.000000', DB::table('exception_monitor_issues')->value('snoozed_until'));
    }

    public function test_unsafe_source_links_and_current_source_config_never_rehydrate_older_evidence(): void
    {
        $this->capture();
        $this->process();
        $id = $this->id();
        config([
            'exception-monitor.capture.release' => 'later',
            'exception-monitor.capture.source_link_template' => 'https://github.com/o/r/blob/{release}/{file}',
        ]);
        $this->getJson('/exception-monitor/api/v1/issues/'.$id)->assertOk()->assertJsonPath('exception_monitor_private.selected.source_url', null);
        $source = app(SourceSnapshot::class);
        foreach ([
            'javascript:alert({file})/{release}',
            'https://token:password@github.com/{release}/{file}',
            'https://github.com/{release}/{file}?token=x',
        ] as $template) {
            config(['exception-monitor.capture.source_link_template' => $template]);
            $this->assertNull($source->capture()['template']);
        }
        $this->assertNull($source->link(['release' => 'abc', 'template' => 'https://github.com/o/r/blob/{release}/{file}'], '../.env', 1));
    }

    public function test_recovered_group_regression_badge_follows_event_time_metrics_not_alert_generation(): void
    {
        $this->capture();
        DB::table('exception_monitor_capture_inbox')->update(['mode' => 'reconciliation']);
        $this->process();
        $this->action('resolve')->assertOk();
        $this->travel(1)->seconds();
        $this->capture();
        $this->process();
        $this->assertSame(1, (int) DB::table('exception_monitor_issues')->value('alert_generation'));
        $this->getJson('/exception-monitor/api/v1/issues/'.$this->id())->assertOk()->assertJsonPath('exception_monitor_private.issue.regressions', true);
    }

    public function test_authoritative_route_snapshot_respects_the_host_request_watcher(): void
    {
        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $previous = $console->getValue($this->app);
        $console->setValue($this->app, false);
        try {
            Route::get('/phase3/orders/{order}', fn () => throw new \RuntimeException('Route snapshot'))->name('checkout.show');
            $this->get('/phase3/orders/42')->assertStatus(500);
            Telescope::store(app(EntriesRepository::class));
            $this->importSource();
            $this->process();
            $this->assertSame('checkout.show', DB::table('exception_monitor_occurrence_evidence')->value('route_name'));
            $this->assertSame('/phase3/orders/{order}', DB::table('exception_monitor_occurrence_evidence')->value('path_template'));
            config(['telescope.watchers.'.RequestWatcher::class => false]);
            Telescope::startRecording(false);
            $this->get('/phase3/orders/43')->assertStatus(500);
            Telescope::store(app(EntriesRepository::class));
            $this->importSource();
            $this->process();
            $this->assertNull(DB::table('exception_monitor_occurrence_evidence')->orderByDesc('id')->value('route_name'));
        } finally {
            $console->setValue($this->app, $previous);
        }
    }
}
