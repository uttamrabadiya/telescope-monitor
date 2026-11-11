<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use TelescopeMonitor\Models\AlertEvent;
use TelescopeMonitor\Models\Issue;
use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Repositories\Contracts\AlertEventRepository;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Storage\Database;

final class EloquentAlertEventRepository implements AlertEventRepository
{
    public function record(Issue $issue, Occurrence $occurrence, string $type, array $payload, int $generation): void
    {
        $payload['count'] = (string) $issue->lifetime_count;
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $event = AlertEvent::query()->firstOrCreate([
            'installation_id' => $issue->installation_id,
            'generation' => $generation,
            'event_key' => hash('sha256', $type.':'.$occurrence->source_epoch.':'.$occurrence->source_uuid),
        ], [
            'public_id' => (string) Str::uuid(),
            'issue_id' => $issue->id,
            'event_type' => $type,
            'occurrence_id' => $occurrence->id,
            'issue_state_version' => $issue->state_version,
            'alert_generation' => $issue->alert_generation,
            'occurred_at' => $occurrence->occurred_at,
            'recorded_at' => CarbonImmutable::now('UTC'),
            'provenance' => $occurrence->mode,
            'payload_ciphertext' => Crypt::encryptString($json),
            'payload_digest' => hash('sha256', $json),
            'planning_state' => 'disabled',
            'expires_at' => CarbonImmutable::now('UTC')->addDays(30),
        ]);
        if ($event->wasRecentlyCreated) {
            app(AlertPlanning::class)->event($event, $issue);
        }
    }

    public function prune(int $limit): int
    {
        $query = AlertEvent::query()->whereIn('planning_state', ['disabled', 'cancelled'])->where('expires_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'));
        if (Database::schema()->hasTable('exception_monitor_deliveries')) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_deliveries')->whereColumn('event_id', 'exception_monitor_alert_events.id'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exception_monitor_delivery_rules')->whereColumn('matching_event_id', 'exception_monitor_alert_events.id'));
        }
        $ids = $query->orderBy('id')->limit($limit)->pluck('id');

        return AlertEvent::query()->whereKey($ids)->delete();
    }
}
