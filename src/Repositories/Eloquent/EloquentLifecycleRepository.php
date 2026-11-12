<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use TelescopeMonitor\Investigation\LifecycleConflict;
use TelescopeMonitor\Models\ActionReceipt;
use TelescopeMonitor\Models\Activity;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\InvestigationRepository;
use TelescopeMonitor\Repositories\Contracts\LifecycleRepository;
use TelescopeMonitor\Storage\Database;

final class EloquentLifecycleRepository implements LifecycleRepository
{
    public function __construct(private InstallationRepository $installations, private InvestigationRepository $queries) {}

    public function act(string $id, string $actor, string $actorHash, string $clientKey, string $digest, array $data): array
    {
        return Database::connection()->transaction(function () use ($id, $actor, $actorHash, $clientKey, $digest, $data) {
            $i = $this->installations->fence();
            if ($i->state !== 'active') {
                throw new LifecycleConflict('The installation is not active. Reload after maintenance.');
            }
            $receipt = ActionReceipt::query()->createOrFirst([
                'installation_id' => $i->id,
                'actor_lookup_hash' => $actorHash,
                'operation' => 'issue.lifecycle',
                'client_key' => $clientKey,
            ], [
                'public_id' => (string) Str::uuid(),
                'generation' => $i->generation,
                'actor_key' => $actor,
                'keyed_request_digest' => $digest,
                'state' => 'pending',
                'expires_at' => CarbonImmutable::now('UTC')->addDays(30),
            ]);
            $receipt = ActionReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($receipt->keyed_request_digest, $digest) || $receipt->generation !== $i->generation) {
                throw new LifecycleConflict('This action key belongs to a different request. Reload and try again.');
            }
            $issue = $this->queries->issue($i->id, $id, true);
            if (! $issue) {
                abort(404);
            }
            if ($receipt->state === 'completed') {
                return ['id' => $issue->public_id, 'state_version' => $issue->state_version, 'replayed' => true];
            }
            if ($issue->state_version !== $data['version']) {
                throw new LifecycleConflict('This error changed. Reload its current state before trying again.', ['status' => $issue->status, 'state_version' => $issue->state_version]);
            }
            $this->expireIssue($issue);
            $previous = $issue->status;
            $action = $data['action'];
            $target = match ($action) {
                'resolve' => 'resolved','ignore' => 'ignored','snooze' => 'snoozed',default => 'unresolved'
            };
            $valid = match ($action) {
                'resolve' => in_array($previous, ['unresolved', 'resolved'], true),'snooze' => $previous === 'unresolved','reopen' => in_array($previous, ['resolved', 'ignored', 'unresolved'], true),'unsnooze' => in_array($previous, ['snoozed', 'unresolved'], true),'ignore' => true, default => false
            };
            if (! $valid) {
                throw new LifecycleConflict('That action is unavailable in the current state. Reload this error.', ['status' => $previous, 'state_version' => $issue->state_version]);
            }
            if ($previous !== $target) {
                $now = CarbonImmutable::now('UTC');
                $issue->status = $target;
                $issue->state_version++;
                $issue->notification_cutoff_at = $issue->notification_cutoff_at?->max($now) ?? $now;
                $issue->resolved_at = $target === 'resolved' ? $now : null;
                if ($target === 'resolved') {
                    $issue->resolution_cutoff_at = $issue->resolution_cutoff_at?->max($now) ?? $now;
                }
                $issue->snoozed_at = $target === 'snoozed' ? $now : null;
                $issue->snoozed_until = $target === 'snoozed' ? CarbonImmutable::parse($data['until'], 'UTC') : null;
                $issue->save();
                $this->activity($issue, 'error.'.$action, $receipt->public_id, $actor, ['previous' => $previous, 'status' => $target, 'snoozed_until' => $issue->snoozed_until?->toISOString()]);
                if (in_array($action, ['resolve', 'ignore', 'snooze'], true)) {
                    app(AlertPlanning::class)->cancelOccurrence($issue);
                    AlertEvent::query()->where('installation_id', $i->id)->where('issue_id', $issue->id)->whereIn('planning_state', ['disabled', 'pending'])->update(['planning_state' => 'cancelled']);
                }
            }
            $receipt->state = 'completed';
            $receipt->result_type = 'issue';
            $receipt->result_public_id = $issue->public_id;
            $receipt->resulting_row_version = $issue->state_version;
            $receipt->completed_at = CarbonImmutable::now('UTC');
            $receipt->save();

            return ['id' => $issue->public_id, 'state_version' => $issue->state_version, 'replayed' => false];
        }, 5);
    }

    private function activity(Issue $issue, string $type, string $action, ?string $actor, array $metadata): void
    {
        Activity::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $issue->installation_id,
            'issue_id' => $issue->id,
            'event_type' => $type,
            'actor_kind' => $actor === null ? 'system' : 'user',
            'actor_key' => $actor,
            'subject_type' => 'issue',
            'subject_public_id' => $issue->public_id,
            'action_public_id' => $action,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => $metadata,
            'expires_at' => CarbonImmutable::now('UTC')->addDays(app(RetentionPolicy::class)->effective()['activity_days']),
        ]);
        app(AlertPlanning::class)->activity($issue, $type, $action, $actor, $metadata);
    }

    private function expireIssue(Issue $issue): bool
    {
        if ($issue->status !== 'snoozed' || (! $issue->snoozed_until || $issue->snoozed_until > CarbonImmutable::now('UTC'))) {
            return false;
        }
        $deadline = $issue->snoozed_until;
        $issue->status = 'unresolved';
        $issue->notification_cutoff_at = $issue->notification_cutoff_at?->max($deadline) ?? $deadline;
        $issue->snoozed_at = null;
        $issue->snoozed_until = null;
        $issue->state_version++;
        $issue->save();
        $this->activity($issue, 'error.snooze_expired', (string) Str::uuid(), null, ['previous' => 'snoozed', 'status' => 'unresolved']);

        return true;
    }

    public function expire(int $limit): int
    {
        $installation = $this->installations->current();
        $ids = Issue::query()->where('installation_id', $installation->id)->where('status', 'snoozed')->where('snoozed_until', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orderBy('id')->limit($limit)->pluck('public_id');
        $count = 0;
        foreach ($ids as $id) {
            $count += Database::connection()->transaction(function () use ($id) {
                $i = $this->installations->fence();
                if ($i->state !== 'active') {
                    return 0;
                }$issue = $this->queries->issue($i->id, $id, true);

                return $issue && $this->expireIssue($issue) ? 1 : 0;
            }, 5);
        }

        return $count;
    }
}
