<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use TelescopeMonitor\Models\Activity;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\ActivityRepository;

final class EloquentActivityRepository implements ActivityRepository
{
    public function record(Issue $issue, string $type, string $action, CarbonImmutable $time, string $mode): void
    {
        Activity::query()->firstOrCreate([
            'installation_id' => $issue->installation_id,
            'action_public_id' => $action,
            'event_type' => $type,
            'subject_public_id' => $issue->public_id,
        ], [
            'public_id' => (string) Str::uuid(),
            'issue_id' => $issue->id,
            'actor_kind' => 'system',
            'subject_type' => 'issue',
            'occurred_at' => $time,
            'metadata' => ['provenance' => $mode],
            'expires_at' => CarbonImmutable::now('UTC')->addDays(app(RetentionPolicy::class)->effective()['activity_days']),
        ]);
    }

    public function prune(int $limit): int
    {
        $ids = Activity::query()->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orderBy('id')->limit($limit)->pluck('id');

        return Activity::query()->whereKey($ids)->delete();
    }
}
