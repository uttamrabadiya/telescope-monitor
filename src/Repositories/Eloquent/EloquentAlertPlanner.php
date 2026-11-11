<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use TelescopeMonitor\Alerts\DeliveryDispatcher;
use TelescopeMonitor\Alerts\EventCatalog;
use TelescopeMonitor\Integrations\ConnectionEvents;
use TelescopeMonitor\Integrations\GitHubIssues;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\AlertRule;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Delivery;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class EloquentAlertPlanner implements AlertPlanning
{
    private ?bool $available = null;

    public function __construct(private EloquentRuleMatcher $matcher, private InstallationRepository $installations, private DeliveryDispatcher $dispatcher) {}

    public function ready(): bool
    {
        return $this->available ??= Database::schema()->hasTable('exception_monitor_deliveries') && Database::schema()->hasTable('exception_monitor_alert_rules') && Database::schema()->hasTable('exception_monitor_notification_gates');
    }

    public function event(AlertEvent $event, Issue $issue): void
    {
        if (! $this->ready() || ! $this->matcher->allowed($issue) || ! in_array($event->provenance, ['live', 'manual', 'user_action'], true)) {
            return;
        }
        $rules = AlertRule::query()->where('installation_id', $issue->installation_id)->where('enabled', true)->whereNull('deleted_at')->orderBy('id')->get();
        foreach ($rules as $rule) {
            if ($this->matcher->matches($rule, $issue, $event)) {
                $this->plan($event, $issue, $rule);
            }
        }
        app(GitHubIssues::class)->automatic($event, $issue);
        $event->planning_state = 'planned';
        $event->save();
    }

    private function plan(AlertEvent $event, Issue $issue, AlertRule $rule): void
    {
        $connections = Connection::query()->join('exception_monitor_rule_connections as rc', 'rc.connection_id', '=', 'exception_monitor_connections.id')->where('rc.rule_id', $rule->id)->where('installation_id', $issue->installation_id)->where('enabled', true)->whereNull('disconnected_at')->get(['exception_monitor_connections.*']);
        foreach ($connections as $connection) {
            if (! ConnectionEvents::allows($connection, $event->event_type)) {
                continue;
            }
            $family = EventCatalog::occurrence($event->event_type) ? 'occurrence' : 'activity:'.EventCatalog::canonical($event->event_type);
            $identity = $family === 'occurrence' ? ('occurrence:'.$event->occurrence_id) : ('action:'.$event->action_public_id.':'.EventCatalog::canonical($event->event_type));
            $delivery = Delivery::query()->createOrFirst(['installation_id' => $issue->installation_id, 'generation' => $event->generation, 'dedupe_key' => hash('sha256', $identity.':'.$connection->target_fingerprint), 'revision' => 1], ['public_id' => (string) Str::uuid(), 'issue_id' => $issue->id, 'event_id' => $event->id, 'connection_id' => $connection->id, 'action' => 'notify', 'target_fingerprint' => $connection->target_fingerprint, 'actor_required' => false, 'event_family' => $family, 'alert_generation' => $event->alert_generation, 'status' => 'pending', 'eligible_at' => CarbonImmutable::now('UTC'), 'expires_at' => CarbonImmutable::now('UTC')->addSeconds(max(60, min(86400, (int) config('exception-monitor.alerts.pending_seconds', 86400))))]);
            Database::connection()->table('exception_monitor_delivery_rules')->insertOrIgnore(['delivery_id' => $delivery->id, 'rule_id' => $rule->id, 'rule_public_id' => $rule->public_id, 'rule_version' => $rule->row_version, 'matching_event_id' => $event->id, 'conditions_snapshot' => json_encode(['events' => $rule->event_types, 'conditions' => $rule->conditions, 'threshold_count' => $rule->threshold_count, 'window_seconds' => $rule->window_seconds, 'cooldown_seconds' => $rule->cooldown_seconds], JSON_THROW_ON_ERROR)]);
            if ($delivery->wasRecentlyCreated) {
                Database::connection()->afterCommit(fn () => $this->dispatcher->dispatch($delivery->id, $delivery->generation));
            }
        }
    }

    public function occurrence(Issue $issue, Occurrence $occurrence): void
    {
        if (! $this->ready() || ! $this->matcher->allowed($issue) || $occurrence->mode !== 'live') {
            return;
        }
        foreach (AlertRule::query()->where('installation_id', $issue->installation_id)->where('enabled', true)->whereNull('deleted_at')->whereNotNull('threshold_count')->get() as $rule) {
            if (! in_array('error.threshold_reached', $rule->event_types, true)) {
                continue;
            }
            $seconds = $rule->window_seconds;
            if (! in_array($seconds, [300, 900, 3600], true)) {
                continue;
            }
            $start = CarbonImmutable::createFromTimestampUTC((int) (floor($occurrence->occurred_at->timestamp / $seconds) * $seconds));
            $end = $start->addSeconds($seconds);
            $candidate = new AlertEvent(['event_type' => 'error.threshold_reached', 'occurrence_id' => $occurrence->id]);
            if (! $this->matcher->matches($rule, $issue, $candidate)) {
                continue;
            }
            $count = $this->matcher->thresholdCount($rule, $issue, $start, $end, $occurrence);
            if ($count !== $rule->threshold_count) {
                continue;
            }
            $event = $this->createEvent($issue, 'error.threshold_reached', hash('sha256', 'threshold:'.$rule->public_id.':'.$rule->row_version.':'.$issue->id.':'.$start->timestamp), $occurrence->occurred_at, $occurrence->id, null, null, 'live', ['count' => (string) $count, 'window_start' => $start->toISOString(), 'window_seconds' => $seconds, 'threshold_rule_version' => $rule->row_version]);
            if ($event->wasRecentlyCreated) {
                $this->plan($event, $issue, $rule);
                $event->planning_state = 'planned';
                $event->save();
            }
        }
    }

    public function activity(Issue $issue, string $type, string $action, ?string $actor, array $metadata = []): void
    {
        if ($issue->is_test) {
            return;
        }
        $latest = Occurrence::query()->where('installation_id', $issue->installation_id)->where('issue_id', $issue->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $event = $this->createEvent($issue, $type, hash('sha256', 'activity:'.$type.':'.$action), CarbonImmutable::now('UTC'), $latest?->id, $action, $actor, 'user_action', array_intersect_key($metadata, array_flip(['note_id'])));
        if ($event->wasRecentlyCreated) {
            $this->event($event, $issue);
        }
    }

    private function createEvent(Issue $issue, string $type, string $key, CarbonImmutable $time, ?int $occurrence, ?string $action, ?string $actor, string $provenance, array $extra): AlertEvent
    {
        $payload = json_encode(array_merge(['class' => $issue->exception_class, 'message' => $issue->message, 'file' => $issue->file, 'line' => $issue->line, 'count' => (string) $issue->lifetime_count], $extra), JSON_THROW_ON_ERROR);

        return AlertEvent::query()->createOrFirst(['installation_id' => $issue->installation_id, 'generation' => $this->installations->current()->generation, 'event_key' => $key], ['public_id' => (string) Str::uuid(), 'issue_id' => $issue->id, 'event_type' => $type, 'occurrence_id' => $occurrence, 'action_public_id' => $action, 'issue_state_version' => $issue->state_version, 'alert_generation' => $issue->alert_generation, 'occurred_at' => $time, 'recorded_at' => CarbonImmutable::now('UTC'), 'provenance' => $provenance, 'actor_key' => $actor, 'payload_ciphertext' => Crypt::encryptString($payload), 'payload_digest' => hash('sha256', $payload), 'planning_state' => 'disabled', 'expires_at' => CarbonImmutable::now('UTC')->addDays(30)]);
    }

    public function cancelOccurrence(Issue $issue): void
    {
        if (! $this->ready()) {
            return;
        }
        Delivery::query()->where('installation_id', $issue->installation_id)->where('issue_id', $issue->id)->where('event_family', 'occurrence')->whereIn('status', ['pending', 'retrying', 'failed'])->update(['status' => 'cancelled', 'cancellation_reason' => 'issue_suppressed', 'completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);
    }
}
