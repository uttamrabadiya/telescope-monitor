<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Alerts\EventCatalog;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\AlertRule;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Operations\RetentionPolicy;

final class EloquentRuleMatcher
{
    public function allowed(Issue $issue): bool
    {
        return config('exception-monitor.alerts.enabled', true) && ! $issue->is_test && in_array($issue->environment, config('exception-monitor.alerts.allowed_environments', ['production']), true);
    }

    public function matches(AlertRule $rule, Issue $issue, AlertEvent $event): bool
    {
        $type = EventCatalog::canonical($event->event_type);
        $c = $rule->conditions;
        if ($rule->getAttribute('definition_version') !== 1 || ! $this->allowed($issue) || ! $rule->enabled || $rule->deleted_at || ! in_array($type, $rule->event_types, true) || ! in_array($issue->environment, $c['environments'] ?? [], true)) {
            return false;
        }
        if (($c['class'] ?? null) !== null && $c['class'] !== '' && $c['class'] !== $issue->exception_class) {
            return false;
        }
        if (($c['tag'] ?? null) && ! in_array(explode(':', $c['tag'], 2)[0], config('exception-monitor.capture.allowed_tags', []), true)) {
            return false;
        }
        if (($c['origin'] ?? null) || ($c['tag'] ?? null)) {
            if (! $event->occurrence_id) {
                return false;
            }
            $q = Occurrence::query()->where('id', $event->occurrence_id)->where('installation_id', $issue->installation_id);
            if ($c['origin'] ?? null) {
                $q->where('origin', $c['origin']);
            }
            if ($c['tag'] ?? null) {
                $this->tag($q, $c['tag']);
            }
            if (! $q->exists()) {
                return false;
            }
        }

        return true;
    }

    private function tag($q, string $tag): void
    {
        [$key,$value] = array_pad(explode(':', $tag, 2), 2, '');
        $q->whereExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_occurrence_evidence as ev')->join('exception_monitor_evidence_tags as t', 't.evidence_id', '=', 'ev.id')->whereColumn('ev.occurrence_id', 'exception_monitor_occurrences.id')->where('ev.expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))->where('exception_monitor_occurrences.occurred_at', '>', app(RetentionPolicy::class)->floors()['evidence'])->where('t.tag_key', $key)->where('t.tag_value', $value));
    }

    public function thresholdCount(AlertRule $rule, Issue $issue, CarbonImmutable $start, CarbonImmutable $end, Occurrence $through): int
    {
        $q = Occurrence::query()->where('installation_id', $issue->installation_id)->where('issue_id', $issue->id)->where('is_test', false)->where('mode', '!=', 'historical_import')->where('occurred_at', '>=', $start->format('Y-m-d H:i:s.u'))->where('occurred_at', '<', $end->format('Y-m-d H:i:s.u'))->where('id', '<=', $through->id);
        if ($rule->conditions['origin'] ?? null) {
            $q->where('origin', $rule->conditions['origin']);
        }
        if ($rule->conditions['tag'] ?? null) {
            $this->tag($q, $rule->conditions['tag']);
        }

        return $q->count();
    }

    public function eligible(AlertEvent $event, Issue $issue): bool
    {
        $type = EventCatalog::canonical($event->event_type);
        if (EventCatalog::occurrence($type)) {
            return $issue->status === 'unresolved' && (! $issue->notification_cutoff_at || $event->occurred_at > $issue->notification_cutoff_at) && $issue->alert_generation === $event->alert_generation;
        }

        return match ($type) {
            'error.resolved' => $issue->status === 'resolved' && $issue->state_version === $event->issue_state_version,'error.reopened','error.unsnoozed','error.snooze_expired' => $issue->status === 'unresolved' && $issue->state_version === $event->issue_state_version,'error.ignored' => $issue->status === 'ignored' && $issue->state_version === $event->issue_state_version,'error.snoozed' => $issue->status === 'snoozed' && $issue->snoozed_until > CarbonImmutable::now('UTC'),'note.created','assignment.changed' => true,default => false
        };
    }
}
