<?php

namespace TelescopeMonitor\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use TelescopeMonitor\Integrations\Credentials;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class RetentionSweep
{
    public function __construct(private InstallationRepository $installations, private RetentionPolicy $policy, private OperationJournal $journal) {}

    private function batch(callable $work): array
    {
        return Database::connection()->transaction(function () use ($work) {
            $i = $this->installations->fence();
            if ($i->state !== 'active') {
                throw new MaintenanceFailure('maintenance_in_progress');
            }

            return $work($i->id);
        }, 5);
    }

    private function table(string $name, int $i)
    {
        return Database::connection()->table('exception_monitor_'.$name)->where('installation_id', $i);
    }

    private function erase($query, int $limit): int
    {
        $ids = (clone $query)->orderBy('id')->limit($limit)->pluck('id');

        return $query->whereIn('id', $ids)->delete();
    }

    private function evidence(int $i): Builder
    {
        $floor = $this->policy->floors()['evidence'];

        return $this->table('occurrence_evidence', $i)->where(fn ($q) => $q->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orWhereExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_occurrences as o')->whereColumn('o.id', 'exception_monitor_occurrence_evidence.occurrence_id')->where('o.occurred_at', '<=', $floor)));
    }

    private function metrics(int $i)
    {
        $f = $this->policy->floors();

        return $this->table('metric_buckets', $i)->where(fn ($q) => $q->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orWhere(fn ($q) => $q->where('resolution', '5m')->where('bucket_start_at', '<', $f['five_minute']))->orWhere(fn ($q) => $q->where('resolution', '1h')->where('bucket_start_at', '<', $f['hourly'])));
    }

    private function activity(int $i)
    {
        return $this->table('activity', $i)->where(fn ($q) => $q->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orWhere('occurred_at', '<=', $this->policy->floors()['activity']));
    }

    private function terminalDeliveries(int $i)
    {
        return $this->table('deliveries', $i)->whereIn('status', ['sent', 'cancelled', 'failed'])->where('completed_at', '<=', $this->policy->floors()['deliveries'])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_deliveries as children')->whereColumn('children.parent_delivery_id', 'exception_monitor_deliveries.id'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_linked_issues as links')->whereColumn('links.create_delivery_id', 'exception_monitor_deliveries.id')->whereIn('links.state', ['reserved', 'creating', 'needs_review']));
    }

    private function events(int $i)
    {
        return $this->table('alert_events', $i)->where(fn ($q) => $q->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orWhere('recorded_at', '<=', $this->policy->floors()['deliveries']))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_deliveries as d')->whereColumn('d.event_id', 'exception_monitor_alert_events.id'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_delivery_rules as refs')->whereColumn('refs.matching_event_id', 'exception_monitor_alert_events.id'));
    }

    public function deliveries(int $limit): array
    {
        return $this->batch(function ($i) use ($limit) {
            $now = now('UTC')->format('Y-m-d H:i:s.u');
            $ids = $this->table('deliveries', $i)->whereIn('status', ['pending', 'retrying'])->where('expires_at', '<=', $now)->orderBy('id')->limit($limit)->pluck('id');
            $this->table('deliveries', $i)->whereIn('id', $ids)->update(['status' => 'cancelled', 'cancellation_reason' => 'expired', 'completed_at' => $now]);
            $expired = fn () => $this->table('deliveries', $i)->where(fn ($q) => $q->where('status', 'cancelled')->orWhere(fn ($q) => $q->where('status', 'failed')->where('expires_at', '<=', $now)))->select('id');
            $links = $this->table('linked_issues', $i)->where('state', 'reserved')->whereIn('create_delivery_id', $expired())->orderBy('id')->limit($limit)->pluck('id');
            $this->table('linked_issues', $i)->whereIn('id', $links)->where('state', 'reserved')->whereIn('create_delivery_id', $expired())->update(['state' => 'unavailable', 'row_version' => Database::connection()->raw('row_version + 1')]);
            $removed = $this->erase($this->terminalDeliveries($i), $limit);
            $events = $this->erase($this->events($i), $limit);
            app(Credentials::class)->cleanup();

            return ['deliveries' => $removed, 'events' => $events];
        });
    }

    private function receipts(int $i, int $limit): int
    {
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $probes = $this->table('action_receipts', $i)->where('operation', 'capture_probe')->whereIn('state', ['pending', 'captured'])->where('expires_at', '<=', $now)->orderBy('id')->limit($limit)->pluck('id');
        $this->table('action_receipts', $i)->whereIn('id', $probes)->update(['state' => 'expired', 'completed_at' => $now]);
        $q = $this->table('action_receipts', $i)->whereNotIn('state', ['pending', 'captured'])->where('expires_at', '<=', $now)->where('created_at', '<=', now('UTC')->subDay()->format('Y-m-d H:i:s.u'));
        $q->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_deliveries as d')->join('exception_monitor_alert_events as e', 'e.id', '=', 'd.event_id')->whereIn('d.status', ['pending', 'processing', 'retrying', 'needs_review'])->where(fn ($q) => $q->whereColumn('e.action_public_id', 'exception_monitor_action_receipts.public_id')->orWhereColumn('e.action_public_id', 'exception_monitor_action_receipts.result_public_id')->orWhereColumn('d.public_id', 'exception_monitor_action_receipts.result_public_id')));
        $q->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_linked_issues as l')->join('exception_monitor_deliveries as d', 'd.id', '=', 'l.create_delivery_id')->whereColumn('l.public_id', 'exception_monitor_action_receipts.result_public_id')->whereIn('d.status', ['pending', 'processing', 'retrying', 'needs_review']));

        return $this->erase($q, $limit);
    }

    public function run(int $limit): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new MaintenanceFailure('invalid_batch_limit');
        }

        return $this->journal->run('retention', function () use ($limit) {
            Database::connection()->transaction(function () {
                $i = $this->installations->lock();
                if ($i->state !== 'active') {
                    throw new MaintenanceFailure('maintenance_in_progress');
                }$v = $this->policy->effective();
                $this->policy->remember($i, $v, $v);
            }, 5);
            $evidence = $this->batch(fn ($i) => ['evidence' => $this->erase($this->evidence($i), $limit)]);
            $metrics = $this->batch(fn ($i) => ['metric_buckets' => $this->erase($this->metrics($i), $limit)]);
            $activity = $this->batch(fn ($i) => ['activity' => $this->erase($this->activity($i), $limit)]);
            $inbox = $this->batch(fn ($i) => ['inbox' => app(CaptureInboxRepository::class)->prune($limit, $this->policy->effective()['occurrence_days'])]);
            $deliveries = $this->deliveries($limit);
            $receipts = $this->batch(fn ($i) => ['receipts' => $this->receipts($i, $limit)]);

            return $evidence + $metrics + $activity + $inbox + $deliveries + $receipts + ['disabled_events' => $deliveries['events']];
        });
    }

    public function preview(array $draft): array
    {
        $draft = $this->policy->validate($draft);
        $before = $this->policy->effective();
        $proposed = $this->policy->effective(array_replace($before, $draft));
        $i = $this->installations->current()->id;
        $cap = max(100, min(100000, (int) config('exception-monitor.operations.preview_limit', 10000)));
        $values = [];
        foreach (['occurrence_days' => ['occurrence_evidence', 'occurrence_id'], 'delivery_days' => ['deliveries', 'completed_at'], 'aggregate_days' => ['metric_buckets', 'bucket_start_at'], 'activity_days' => ['activity', 'occurred_at']] as $key => [$table,$column]) {
            $floor = now('UTC')->subDays($proposed[$key])->format('Y-m-d H:i:s.u');
            $q = $this->table($table, $i);
            if ($table === 'occurrence_evidence') {
                $q->whereExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_occurrences as o')->whereColumn('o.id', 'exception_monitor_occurrence_evidence.occurrence_id')->where('o.occurred_at', '<=', $floor));
            } else {
                $q->where($column, '<=', $floor);
            }
            if ($table === 'deliveries') {
                $q->whereIn('status', ['sent', 'failed', 'cancelled']);
            }if ($table === 'metric_buckets') {
                $q->where('resolution', '1h');
            }
            $count = $q->limit($cap + 1)->get([Database::connection()->raw('1 AS bounded_row')])->count();
            $values[$key] = ['eligible_count' => min($cap, $count), 'at_least' => $count > $cap, 'reduction' => $proposed[$key] < $before[$key]];
        }

        return ['proposed' => $proposed, 'counts' => $values, 'limit' => $cap, 'as_of' => CarbonImmutable::now('UTC')->toISOString(), 'next_prune' => 'Host scheduler controlled; no future run is guaranteed.', 'preserves' => ['summaries and lifetime counts', 'minimal occurrence UUID identity', 'notes', 'saved views and preferences', 'GitHub associations'], 'note' => 'Estimates are bounded eligible-row counts, not a promise of one-batch deletion. Active references and parent chains may defer history cleanup. Increasing retention cannot restore expired evidence.'];
    }
}
