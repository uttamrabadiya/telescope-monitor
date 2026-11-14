<?php

namespace TelescopeMonitor\Reports;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Activity;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\SettingsService;

final class ReportBuilder
{
    private int $installationId;

    private array $floors;

    public function __construct(private InstallationRepository $installations, private SettingsService $settings, private RetentionPolicy $retention) {}

    /** Calendar boundaries are calculated locally, then queried as half-open UTC ranges. */
    public function period(string $kind, string $timezone, CarbonImmutable $now): array
    {
        $end = $now->setTimezone($timezone)->startOfDay();
        if ($kind === 'weekly') {
            $end = $end->startOfWeek(CarbonImmutable::MONDAY);
        }
        $start = $kind === 'daily' ? $end->subDay() : $end->subWeek();
        $previous = $kind === 'daily' ? $start->subDay() : $start->subWeek();

        return [$start, $end, $previous];
    }

    private function sql(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s.u');
    }

    private function issues(array $environments)
    {
        return Issue::query()->where('installation_id', $this->installationId)->where('is_test', false)->whereIn('environment', $environments);
    }

    private function occurrences(array $environments, CarbonImmutable $start, CarbonImmutable $end)
    {
        return Occurrence::query()->where('installation_id', $this->installationId)->where('is_test', false)->whereIn('issue_id', $this->issues($environments)->select('id'))->where('occurred_at', '>=', $this->sql($start))->where('occurred_at', '<', $this->sql($end))->where('occurred_at', '>=', $this->floors['hourly']);
    }

    private function activities(array $environments, CarbonImmutable $start, CarbonImmutable $end)
    {
        return Activity::query()->where('installation_id', $this->installationId)->whereIn('issue_id', $this->issues($environments)->select('id'))->where('occurred_at', '>=', $this->sql($start))->where('occurred_at', '<', $this->sql($end))->where('occurred_at', '>', $this->floors['activity'])->where('expires_at', '>', $this->sql(CarbonImmutable::now('UTC')));
    }

    private function complete(CarbonImmutable $start, string $floor): bool
    {
        $i = $this->installations->current();
        $created = $i->getRawOriginal('created_at');

        return is_string($created) && CarbonImmutable::parse($created, 'UTC') <= $start
            && CarbonImmutable::parse($floor, 'UTC') <= $start
            && (! $i->purged_through_at || $i->purged_through_at < $start);
    }

    public function build(string $kind, array $definition, CarbonImmutable $now): array
    {
        $this->installationId = $this->installations->current()->id;
        $this->floors = $this->retention->floors();
        [$start, $end, $previous] = $this->period($kind, $definition['timezone'], $now);
        $env = $definition['environments'];
        $floors = $this->floors;
        $complete = $this->complete($start, $floors['hourly']);
        $comparison = $complete && $this->complete($previous, $floors['hourly']);
        $activityComplete = $this->complete($start, $floors['activity']);
        $occurrences = $this->occurrences($env, $start, $end);
        $total = (clone $occurrences)->count();
        $old = $comparison ? $this->occurrences($env, $previous, $start)->count() : null;
        $activity = $this->activities($env, $start, $end);
        $regressions = (clone $activity)->where('event_type', 'error.regressed')->count();
        $resolved = (clone $activity)->whereIn('event_type', ['error.resolve', 'error.resolved'])->distinct()->count('issue_id');
        $new = $this->issues($env)->where('first_seen_at', '>=', $this->sql($start))->where('first_seen_at', '<', $this->sql($end))->count();
        $open = $this->issues($env)->where(fn ($q) => $q->where('status', 'unresolved')->orWhere(fn ($q) => $q->where('status', 'snoozed')->where('snoozed_until', '<=', $this->sql($now))));
        $counts = (clone $occurrences)->select('issue_id')->selectRaw('COUNT(*) AS report_count')->groupBy('issue_id');
        $top = (clone $open)->joinSub($counts, 'report_counts', 'report_counts.issue_id', '=', 'telescope_monitor_issues.id')->select('telescope_monitor_issues.*', 'report_counts.report_count')->orderByDesc('report_count')->orderBy('telescope_monitor_issues.id')->limit(3)->get();
        $general = $this->settings->group('general')['values'];
        $base = rtrim($general['dashboard_url'], '/');
        $rows = [];
        foreach ($top as $issue) {
            $regressed = (clone $activity)->where('issue_id', $issue->id)->where('event_type', 'error.regressed')->count();
            $rows[] = [
                'class' => $issue->exception_class,
                'environment' => $issue->environment,
                'count' => (int) $issue->getAttribute('report_count'),
                'regressions' => $activityComplete ? $regressed : 0,
                'status' => $regressed ? 'Regressed' : ($issue->first_seen_at >= $start ? 'New' : 'Open'),
                'unassigned' => $issue->getAttribute('assignee_actor_key') === null,
                'url' => $base.'/errors/'.$issue->public_id,
            ];
        }
        $buckets = [];
        // Use exact occurrence timestamps so half-hour/quarter-hour zones and DST
        // never shift a calendar report onto UTC aggregate-bucket boundaries.
        for ($at = $start; $at < $end; $at = $next) {
            $next = $kind === 'daily' ? $at->utc()->addHour()->setTimezone($definition['timezone']) : $at->addDay();
            $buckets[] = [
                'label' => $kind === 'daily' ? $at->format('H:i T') : $at->format('D'),
                'count' => $this->occurrences($env, $at, $next)->count(),
                'previous' => $kind === 'weekly' && $comparison ? $this->occurrences($env, $at->subWeek(), $next->subWeek())->count() : null,
            ];
        }
        return [
            'kind' => $kind,
            'timezone' => $definition['timezone'],
            'period_start' => $start->toISOString(),
            'period_end' => $end->toISOString(),
            'period_label' => $kind === 'daily' ? $start->format('j M Y') : $start->format('j M').' – '.$end->subDay()->format('j M Y'),
            'environments' => $env,
            'occurrences' => $total,
            'new_groups' => $new,
            'regressions' => $activityComplete ? $regressions : null,
            'resolved' => $activityComplete ? $resolved : null,
            'unresolved' => (clone $open)->count(),
            'aging' => (clone $open)->where('first_seen_at', '<', $this->sql($now->subDays(7)))->count(),
            'complete' => $complete,
            'comparison_available' => $comparison,
            'previous_occurrences' => $old,
            'change_percent' => $comparison && $old > 0 ? round(($total - $old) / $old * 100, 1) : null,
            'activity_complete' => $activityComplete,
            'has_activity' => $total > 0 || $new > 0 || $regressions > 0 || $resolved > 0,
            'top' => $rows,
            'buckets' => $buckets,
            'url' => $base.'/overview',
            'preferences_url' => $base.'/settings/reports',
            'schedule' => ($kind === 'daily' ? 'Daily' : [
                '1' => 'Mondays',
                '2' => 'Tuesdays',
                '3' => 'Wednesdays',
                '4' => 'Thursdays',
                '5' => 'Fridays',
                '6' => 'Saturdays',
                '7' => 'Sundays',
            ][$definition['weekday']]).' at '.$definition['time'].' '.$definition['timezone'],
        ];
    }
}
