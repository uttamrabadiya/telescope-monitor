<?php

namespace TelescopeMonitor\Tests\Investigation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use TelescopeMonitor\Tests\Capture\CapturesExceptions;
use TelescopeMonitor\Tests\TestCase;

final class OverviewTest extends TestCase
{
    use CapturesExceptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorize();
        $this->travelTo(CarbonImmutable::parse('2026-11-11T10:42:00Z'));
    }

    public function test_open_groups_and_origins_follow_the_filtered_cohort_independently_of_resolution_state(): void
    {
        $first = $this->capture('Failure', at: CarbonImmutable::now('UTC')->subHours(2), class: 'ProductionFixture');
        $second = $this->capture('Failure', class: 'ProductionFixture');
        $other = $this->capture('Other', class: 'StagingFixture');
        $this->process();
        DB::table('telescope_monitor_issues')->where('exception_class', 'ProductionFixture')->update(['environment' => 'production']);
        DB::table('telescope_monitor_issues')->where('exception_class', 'StagingFixture')->update(['environment' => 'staging', 'is_test' => true]);
        foreach ([$first => 'web', $second => 'job', $other => 'unknown'] as $uuid => $origin) {
            DB::table('telescope_monitor_occurrences')->where('source_uuid', $uuid)->update(['origin' => $origin]);
        }
        $data = $this->getJson('/telescope-monitor/api/v1/overview?environment=production')->assertOk()->json('telescope_monitor_private');
        $this->assertSame('1', $data['totals']['open_groups']);
        $this->assertSame('2', $data['totals']['occurrences']);
        $this->assertSame(['web' => '1', 'job' => '1', 'command' => '0', 'unknown' => '0'], array_column($data['origins']['items'], 'count', 'value'));
        $this->assertTrue($data['origins']['matches_aggregates']);
        DB::table('telescope_monitor_issues')->where('exception_class', 'ProductionFixture')->update(['status' => 'resolved']);
        $this->getJson('/telescope-monitor/api/v1/overview?environment=production')->assertJsonPath('telescope_monitor_private.totals.open_groups', '0')->assertJsonPath('telescope_monitor_private.totals.occurrences', '2');
        $this->getJson('/telescope-monitor/api/v1/overview')->assertJsonPath('telescope_monitor_private.origins.total', '2');
        $this->getJson('/telescope-monitor/api/v1/overview?include_tests=1')->assertJsonPath('telescope_monitor_private.origins.total', '3');
    }

    public function test_comparison_excludes_partial_intervals_from_both_periods(): void
    {
        foreach ([2, 2, 0, 26, 24, 24, 24, 24, 24, 24, 24, 24, 24] as $hours) {
            $this->capture('Failure', at: CarbonImmutable::now('UTC')->subHours($hours));
        }
        $this->process();
        DB::table('telescope_monitor_installations')->update(['created_at' => CarbonImmutable::now('UTC')->subDays(5)->format('Y-m-d H:i:s')]);
        $data = $this->getJson('/telescope-monitor/api/v1/overview')->assertOk()->json('telescope_monitor_private');
        $this->assertSame('3', $data['totals']['occurrences']);
        $this->assertTrue($data['comparison']['available']);
        $this->assertSame('2', $data['comparison']['current_occurrences']);
        $this->assertSame('1', $data['comparison']['previous_occurrences']);
        $this->assertSame(100, $data['comparison']['change_percent']);
        $this->assertCount(24, $data['comparison']['buckets']);
        $this->assertNull($data['comparison']['buckets'][23]['occurrences']);
    }

    public function test_new_installations_and_retention_limits_do_not_claim_a_comparison_baseline(): void
    {
        $this->capture();
        $this->process();
        $this->getJson('/telescope-monitor/api/v1/overview')->assertOk()->assertJsonPath('telescope_monitor_private.comparison.available', false)->assertJsonPath('telescope_monitor_private.comparison.buckets', [])->assertJsonPath('telescope_monitor_private.comparison.change_percent', null);
        DB::table('telescope_monitor_installations')->update(['created_at' => CarbonImmutable::now('UTC')->subDays(100)->format('Y-m-d H:i:s')]);
        config(['telescope-monitor.locked' => ['retention.aggregate_days' => 30]]);
        $this->getJson('/telescope-monitor/api/v1/overview?range=30d')->assertOk()->assertJsonPath('telescope_monitor_private.comparison.available', false);
    }

    public function test_origins_use_retained_metric_boundaries_and_survive_snapshot_pruning(): void
    {
        $this->capture(at: CarbonImmutable::now('UTC')->subHours(2));
        $this->capture();
        $this->process();
        DB::table('telescope_monitor_occurrence_evidence')->delete();
        DB::table('telescope_monitor_installations')->update(['retention_cutoffs' => json_encode(['hourly' => CarbonImmutable::now('UTC')->subHour()->toISOString()])]);
        $this->getJson('/telescope-monitor/api/v1/overview')->assertOk()->assertJsonPath('telescope_monitor_private.totals.occurrences', '1')->assertJsonPath('telescope_monitor_private.origins.total', '1')->assertJsonPath('telescope_monitor_private.origins.matches_aggregates', true);
        DB::table('telescope_monitor_metric_buckets')->delete();
        $this->getJson('/telescope-monitor/api/v1/overview')->assertJsonPath('telescope_monitor_private.totals.occurrences', '0')->assertJsonPath('telescope_monitor_private.origins.total', '1')->assertJsonPath('telescope_monitor_private.origins.matches_aggregates', false);
    }

    public function test_capture_observations_are_refreshed_by_the_overview_request(): void
    {
        $this->capture();
        $this->process();
        $this->getJson('/telescope-monitor/api/v1/bootstrap')->assertOk();
        $observed = CarbonImmutable::now('UTC')->addMinute()->format('Y-m-d H:i:s.u');
        DB::table('telescope_monitor_capture_inbox')->update(['processed_at' => $observed]);
        $this->getJson('/telescope-monitor/api/v1/overview')->assertOk()->assertJsonPath('telescope_monitor_private.capture.last_processed_at', $observed);
    }
}
