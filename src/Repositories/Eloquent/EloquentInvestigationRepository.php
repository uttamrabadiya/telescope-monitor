<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Collaboration\Assignments;
use TelescopeMonitor\Collaboration\Identity;
use TelescopeMonitor\Investigation\Window;
use TelescopeMonitor\Models\Activity;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\MetricBucket;
use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Models\OccurrenceEvidence;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;
use TelescopeMonitor\Repositories\Contracts\InvestigationRepository;

final class EloquentInvestigationRepository implements InvestigationRepository
{
    public function issue(int $installation, string $id, bool $lock = false): ?Issue
    {
        return Issue::query()->where('installation_id', $installation)->where('public_id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    private function effective(): string
    {
        return "CASE WHEN status = 'snoozed' AND snoozed_until <= '".now('UTC')->format('Y-m-d H:i:s.u')."' THEN 'unresolved' ELSE status END";
    }

    private function groups(int $installation, array $f, Window $w)
    {
        $counts = app(RetentionPolicy::class)->metrics(MetricBucket::query())->select('issue_id')->selectRaw('SUM(occurrence_count) AS range_count, SUM(regression_count) AS range_regressions')->where('installation_id', $installation)->where('resolution', $w->resolution)->where('bucket_start_at', '>=', $w->sql($w->start))->where('bucket_start_at', '<', $w->sql($w->end))->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->groupBy('issue_id');
        $q = Issue::query()->select('exception_monitor_issues.*')->selectRaw('COALESCE(window_counts.range_count,0) AS range_count, COALESCE(window_counts.range_regressions,0) AS range_regressions')->selectRaw($this->effective().' AS effective_status')->leftJoinSub($counts, 'window_counts', 'window_counts.issue_id', '=', 'exception_monitor_issues.id')->where('exception_monitor_issues.installation_id', $installation);
        if (! ($f['include_tests'] ?? false)) {
            $q->where('is_test', false);
        }
        if ($f['environment'] ?? null) {
            $q->where('environment', $f['environment']);
        }
        if (($f['status'] ?? 'unresolved') !== 'all') {
            $q->whereRaw($this->effective().' = ?', [$f['status'] ?? 'unresolved']);
        }
        if ($f['class'] ?? null) {
            $q->where('exception_class', $f['class']);
        }
        if ($f['search'] ?? null) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['search']).'%';
            $q->where(fn ($q) => $q->whereRaw("exception_class LIKE ? ESCAPE '!'", [$term])->orWhereRaw("message LIKE ? ESCAPE '!'", [$term]));
        }
        foreach (['first', 'last'] as $which) {
            foreach (['after' => '>=', 'before' => '<'] as $side => $op) {
                if ($f[$which.'_'.$side] ?? null) {
                    $at = CarbonImmutable::parse($f[$which.'_'.$side], 'UTC');
                    if ($side === 'before') {
                        $at = $at->addDay();
                    }$q->where($which.'_seen_at', $op, $w->sql($at));
                }
            }
        }
        if (isset($f['assignee']) && app(Assignments::class)->enabled()) {
            $key = $f['assignee'];
            if ($key === 'unassigned') {
                $q->whereNull('assignee_actor_key');
            } else {
                $identity = app(Identity::class);
                $q->where('assignee_lookup_hash', $identity->hash($key === 'me' ? $identity->current()->key : (str_starts_with($key, 'actor:') ? substr($key, 6) : $key)));
            }
        }
        if ($f['origin'] ?? null) {
            $q->whereExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_occurrences as o')->whereColumn('o.issue_id', 'exception_monitor_issues.id')->where('o.installation_id', $installation)->where('o.origin', $f['origin']));
        }
        if ($f['tag'] ?? null) {
            [$key,$value] = array_pad(explode(':', $f['tag'], 2), 2, '');
            $q->whereExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_evidence_tags as t')->join('exception_monitor_occurrence_evidence as e', 'e.id', '=', 't.evidence_id')->join('exception_monitor_occurrences as o', 'o.id', '=', 'e.occurrence_id')->whereColumn('o.issue_id', 'exception_monitor_issues.id')->where('t.installation_id', $installation)->where('t.tag_key', $key)->where('t.tag_value', $value)->where('e.expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->where('o.occurred_at', '>', app(RetentionPolicy::class)->floors()['evidence']));
        }

        return $q;
    }

    private function resource(Issue $i): array
    {
        return ['id' => $i->public_id, 'class' => $i->exception_class, 'message' => $i->message, 'file' => $i->file, 'line' => $i->line, 'environment' => $i->environment, 'status' => $i->effective_status ?? ($i->status === 'snoozed' && $i->snoozed_until?->isPast() ? 'unresolved' : $i->status), 'state_version' => $i->state_version, 'lifetime_count' => (string) $i->lifetime_count, 'range_count' => (string) ($i->range_count ?? 0), 'first_seen_at' => $i->first_seen_at->toISOString(), 'last_seen_at' => $i->last_seen_at->toISOString(), 'snoozed_until' => $i->snoozed_until?->toISOString(), 'is_test' => $i->is_test, 'recovered_only' => $i->recovered_only, 'assignee' => app(Assignments::class)->describe($i->assignee_actor_key), 'regressions' => (int) $i->getAttribute('range_regressions') > 0];
    }

    public function inbox(int $installation, array $filters, Window $window): array
    {
        $q = $this->groups($installation, $filters, $window);
        $sort = match ($filters['sort'] ?? 'last_seen') {
            'first_seen' => 'first_seen_at','occurrences' => 'range_count',default => 'last_seen_at'
        };
        $page = $q->orderByDesc($sort)->orderByDesc('exception_monitor_issues.id')->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);
        $ids = $page->getCollection()->pluck('id');
        $origins = Occurrence::query()->where('installation_id', $installation)->whereIn('issue_id', $ids)->select('issue_id', 'origin')->distinct()->get()->groupBy('issue_id');

        return ['items' => $page->getCollection()->map(fn ($i) => array_merge($this->resource($i), ['origins' => isset($origins[$i->id]) ? $origins[$i->id]->pluck('origin')->all() : []]))->all(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => (string) $page->total(), 'last_page' => $page->lastPage(), 'window' => $window->json(), 'environments' => Issue::query()->where('installation_id', $installation)->distinct()->orderBy('environment')->limit(100)->pluck('environment')->all(), 'allowed_tags' => config('exception-monitor.capture.allowed_tags', []), 'coverage' => 'Range counts include all origins for each group. Origin filters use captured identities; tag filters use unexpired retained evidence. The count range does not hide older unresolved groups.'];
    }

    public function overview(int $installation, array $filters, Window $window): array
    {
        $groups = $this->groups($installation, array_merge($filters, ['status' => 'all']), $window)->select('exception_monitor_issues.id');
        $rows = app(RetentionPolicy::class)->metrics(MetricBucket::query())->where('installation_id', $installation)->whereIn('issue_id', $groups)->where('resolution', $window->resolution)->where('bucket_start_at', '>=', $window->sql($window->start))->where('bucket_start_at', '<', $window->sql($window->end))->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->select('bucket_start_at')->selectRaw('SUM(occurrence_count) AS occurrences, SUM(new_group_count) AS groups_count, SUM(regression_count) AS regressions')->groupBy('bucket_start_at')->toBase()->get();
        $buckets = [];
        for ($at = $window->start; $at < $window->end; $at = $at->addSeconds($window->step)) {
            $buckets[$at->timestamp] = ['at' => $at->toISOString(), 'occurrences' => 0, 'new_groups' => 0, 'regressions' => 0];
        }
        foreach ($rows as $row) {
            $key = (int) (floor(CarbonImmutable::parse($row->bucket_start_at, 'UTC')->timestamp / $window->step) * $window->step);
            if (isset($buckets[$key])) {
                $buckets[$key]['occurrences'] += (int) $row->occurrences;
                $buckets[$key]['new_groups'] += (int) $row->groups_count;
                $buckets[$key]['regressions'] += (int) $row->regressions;
            }
        }
        $totals = ['occurrences' => '0', 'new_groups' => '0', 'regressions' => '0'];
        foreach (array_keys($totals) as $key) {
            $totals[$key] = (string) array_sum(array_column($buckets, $key));
        }
        foreach ($buckets as &$bucket) {
            foreach (['occurrences', 'new_groups', 'regressions'] as $key) {
                $bucket[$key] = (string) $bucket[$key];
            }
        }
        unset($bucket);

        $recent = Activity::query()->where('installation_id', $installation)->whereIn('issue_id', $groups)->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->where('occurred_at', '>', app(RetentionPolicy::class)->floors()['activity'])->orderByDesc('occurred_at')->orderByDesc('id')->limit(10)->get();
        $labels = Issue::query()->where('installation_id', $installation)->whereIn('id', $recent->pluck('issue_id'))->get(['id', 'message', 'exception_class'])->keyBy('id');

        return ['window' => $window->json(), 'totals' => $totals, 'buckets' => array_values($buckets), 'errors' => $this->inbox($installation, array_merge($filters, ['status' => 'unresolved', 'sort' => 'occurrences', 'per_page' => 25, 'page' => 1]), $window), 'delivery' => app(AlertPlanning::class)->ready() ? ['state' => 'available', 'detail' => 'Delivery ledger available. Sent means transport acceptance, not inbox receipt.', 'observations' => app(DeliveryLedger::class)->status()] : ['state' => 'unavailable', 'detail' => 'Run alerting migrations to enable durable email delivery.'], 'activity' => $recent->map(function ($a) use ($labels) {
            $label = $labels->get($a->issue_id);

            return ['type' => $a->event_type, 'issue_id' => $a->subject_public_id, 'summary' => $label === null ? 'Unavailable error' : ($label->message ?: $label->exception_class), 'at' => $a->occurred_at->toISOString()];
        })->all()];
    }

    private function activityResource(Activity $activity): array
    {
        $metadata = $activity->metadata;
        foreach (['assignee', 'previous_assignee'] as $key) {
            if (isset($metadata[$key])) {
                $metadata[$key.'_label'] = app(Assignments::class)->describe($metadata[$key])['label'] ?? 'Unavailable assignee';
            }
        }

        return ['id' => $activity->public_id, 'type' => $activity->event_type, 'actor' => $activity->actor_kind === 'user' ? app(Identity::class)->label($activity->actor_key) : 'System', 'at' => $activity->occurred_at->toISOString(), 'metadata' => $metadata];
    }

    public function detail(int $installation, string $id, array $filters, Window $window): ?array
    {
        $issue = $this->groups($installation, ['status' => 'all', 'include_tests' => true], $window)->where('public_id', $id)->first();
        if (! $issue) {
            return null;
        }
        $history = Occurrence::query()->where('installation_id', $installation)->where('issue_id', $issue->id)->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);
        $releases = OccurrenceEvidence::query()->whereIn('occurrence_id', $history->getCollection()->pluck('id'))->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->whereIn('occurrence_id', Occurrence::query()->where('occurred_at', '>', app(RetentionPolicy::class)->floors()['evidence'])->select('id'))->pluck('release', 'occurrence_id');
        $selected = isset($filters['occurrence']) ? Occurrence::query()->where('installation_id', $installation)->where('issue_id', $issue->id)->where('public_id', $filters['occurrence'])->first() : null;
        if (isset($filters['occurrence']) && ! $selected) {
            return null;
        }
        if (! $selected) {
            $selected = Occurrence::query()->join('exception_monitor_occurrence_evidence as e', 'e.occurrence_id', '=', 'exception_monitor_occurrences.id')->where('exception_monitor_occurrences.installation_id', $installation)->where('issue_id', $issue->id)->where('e.expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->where('exception_monitor_occurrences.occurred_at', '>', app(RetentionPolicy::class)->floors()['evidence'])->orderByDesc('occurred_at')->orderByDesc('exception_monitor_occurrences.id')->first(['exception_monitor_occurrences.*']) ?? $history->getCollection()->first();
        }
        $evidence = $selected ? app(EvidenceReader::class)->read($selected->public_id) : null;
        $retained = Occurrence::query()->join('exception_monitor_occurrence_evidence as e', 'e.occurrence_id', '=', 'exception_monitor_occurrences.id')->where('issue_id', $issue->id)->where('exception_monitor_occurrences.installation_id', $installation)->where('e.expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->where('exception_monitor_occurrences.occurred_at', '>', app(RetentionPolicy::class)->floors()['evidence']);
        $breakdowns = [];
        foreach (['origin' => 'exception_monitor_occurrences.origin', 'route' => 'e.route_name', 'release' => 'e.release'] as $key => $column) {
            $breakdowns[$key] = (clone $retained)->whereNotNull($column)->select($column.' as value')->selectRaw('COUNT(*) AS count')->groupBy($column)->orderByDesc('count')->limit(20)->toBase()->get()->map(fn ($r) => ['value' => $r->value, 'count' => (string) $r->count])->all();
        }
        $breakdowns['tags'] = (clone $retained)->join('exception_monitor_evidence_tags as tags', 'tags.evidence_id', '=', 'e.id')->select('tags.tag_key', 'tags.tag_value')->selectRaw('COUNT(*) as count')->groupBy('tags.tag_key', 'tags.tag_value')->orderByDesc('count')->limit(20)->toBase()->get()->map(fn ($r) => ['value' => $r->tag_key.':'.$r->tag_value, 'count' => (string) $r->count])->all();
        $breakdowns['users'] = (clone $retained)->whereNotNull('e.impact_subject_hash')->distinct()->count('e.impact_subject_hash');

        return ['issue' => $this->resource($issue), 'window' => $window->json(), 'selected' => $evidence, 'occurrences' => ['items' => $history->getCollection()->map(fn ($o) => ['id' => $o->public_id, 'at' => $o->occurred_at->toISOString(), 'origin' => $o->origin, 'mode' => $o->mode, 'release' => $releases[$o->id] ?? null])->all(), 'page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'total' => (string) $history->total()], 'insights' => ['retained_count' => (string) (clone $retained)->count(), 'from' => (clone $retained)->min('occurred_at'), 'to' => (clone $retained)->max('occurred_at'), 'breakdowns' => $breakdowns, 'coverage' => 'Insights describe unexpired retained evidence only. Missing categories were excluded or unavailable; they are not zero impact.'], 'activity' => Activity::query()->where('installation_id', $installation)->where('issue_id', $issue->id)->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->where('occurred_at', '>', app(RetentionPolicy::class)->floors()['activity'])->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get()->map(fn ($a) => $this->activityResource($a))->all()];
    }
}
