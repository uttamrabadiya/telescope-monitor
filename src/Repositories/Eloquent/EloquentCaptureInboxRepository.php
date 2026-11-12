<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use TelescopeMonitor\Models\CaptureInbox;
use TelescopeMonitor\Models\HealthCheck;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class EloquentCaptureInboxRepository implements CaptureInboxRepository
{
    private ?bool $available = null;

    public function ready(): bool
    {
        if ($this->available === null) {
            $this->available = true;
            foreach ([
                'capture_inbox',
                'issues',
                'occurrences',
                'occurrence_evidence',
                'evidence_tags',
                'metric_buckets',
                'activity',
                'alert_events',
                'secrets',
            ] as $table) {
                if (! Database::schema()->hasTable('telescope_monitor_'.$table)) {
                    $this->available = false;
                    break;
                }
            }
        }

        return $this->available;
    }

    public function enqueue(array $attributes): CaptureInbox
    {
        $key = array_intersect_key($attributes, array_flip(['installation_id', 'generation', 'capture_key']));

        return CaptureInbox::query()->createOrFirst($key, array_diff_key($attributes, $key));
    }

    public function find(int $id): ?CaptureInbox
    {
        return CaptureInbox::query()->find($id);
    }

    public function lock(int $id): ?CaptureInbox
    {
        return CaptureInbox::query()->lockForUpdate()->find($id);
    }

    public function save(CaptureInbox $item): void
    {
        $item->save();
    }

    public function due(int $limit): array
    {
        return CaptureInbox::query()->where(function ($q) {
            $q->whereIn('state', ['pending', 'retrying'])->where('eligible_at', '<=', now('UTC')->format('Y-m-d H:i:s.u'));
            $q->orWhere(fn ($q) => $q->where('state', 'processing')->where('lease_until', '<=', now('UTC')->format('Y-m-d H:i:s.u')));
        })->orderBy('id')->limit($limit)->pluck('id')->all();
    }

    public function status(): array
    {
        $generation = app(InstallationRepository::class)->current()->generation;
        $checks = HealthCheck::query()->whereIn('check_key', ['capture_received', 'capture_worker'])->get()->keyBy('check_key');
        $observed = function (string $key) use ($checks, $generation): ?string {
            $row = $checks->get($key);
            if (! $row || ($row->sanitized_details['generation'] ?? $generation) !== $generation) {
                return null;
            }

            return $row->last_success_at?->format('Y-m-d H:i:s.u');
        };

        return [
            'pending' => CaptureInbox::query()->whereIn('state', ['pending', 'retrying', 'processing'])->count(),
            'failed' => CaptureInbox::query()->where('state', 'failed')->count(),
            'oldest_pending_at' => CaptureInbox::query()->whereIn('state', ['pending', 'retrying', 'processing'])->min('received_at'),
            'last_error_code' => CaptureInbox::query()->where('state', 'failed')->orderByDesc('id')->value('last_error_code'),
            'last_received_at' => max(CaptureInbox::query()->max('received_at'), $observed('capture_received')),
            'last_processed_at' => max(CaptureInbox::query()->max('processed_at'), $observed('capture_worker')),
        ];
    }

    public function prune(int $limit, int $days): int
    {
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $expired = CaptureInbox::query()->where('received_at', '<', now('UTC')->subDays($days)->format('Y-m-d H:i:s.u'))->whereNotNull('payload_ciphertext')->where(fn ($q) => $q->where('state', '!=', 'processing')->orWhereNull('lease_until')->orWhere('lease_until', '<=', $now))->orderBy('id')->limit($limit)->pluck('id');
        CaptureInbox::query()->whereKey($expired)->whereIn('state', ['pending', 'retrying', 'processing'])->update([
            'state' => 'failed',
            'last_error_code' => 'buffer_retention_expired',
            'completed_at' => $now,
            'claim_token' => null,
            'lease_until' => null,
        ]);
        CaptureInbox::query()->whereKey($expired)->update(['payload_ciphertext' => null]);
        $ids = CaptureInbox::query()->whereIn('state', ['completed', 'cancelled', 'failed'])->where('completed_at', '<', now('UTC')->subDay()->format('Y-m-d H:i:s.u'))->orderBy('id')->limit(max(0, $limit - $expired->count()))->pluck('id');

        return $expired->count() + CaptureInbox::query()->whereKey($ids)->delete();
    }
}
