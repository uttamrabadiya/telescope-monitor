<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Data\Actor;
use TelescopeMonitor\Jobs\CaptureProbe;
use TelescopeMonitor\Repositories\Contracts\ActionReceiptRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Telescope\StoredEntries;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;

final class CaptureProbes
{
    public function __construct(private InstallationRepository $installations, private ActionReceiptRepository $receipts, private ActorResolver $actors, private Capabilities $caps, private CaptureKeys $keys, private CaptureDispatcher $dispatcher, private CaptureContext $context, private StoredEntries $source) {}

    public function request(string $clientKey): array
    {
        $actor = $this->actors->current();
        if (! $actor || ! $this->caps->allows('manage')) {
            throw new CaptureFailure('probe_forbidden');
        }
        $key = $this->keys->key();
        $receipt = Database::connection()->transaction(function () use ($actor, $clientKey, $key) {
            $i = $this->installations->lock();
            if ($i->state !== 'active') {
                throw new CaptureFailure('maintenance_in_progress');
            }

            return $this->receipts->createProbe($i->id, $i->generation, $actor->key, $clientKey, hash_hmac('sha256', 'capture_probe:'.$i->public_id, $key['value']));
        }, 5);
        if ($receipt->wasRecentlyCreated) {
            $this->dispatch($receipt->public_id, $receipt->generation);
        }

        return $this->status($receipt->public_id);
    }

    public function status(string $id): ?array
    {
        $row = $this->receipts->probe($id);
        if (! $row || $row->installation_id !== $this->installations->current()->id) {
            return null;
        }

        return [
            'id' => $row->public_id,
            'state' => $row->generation !== $this->installations->current()->generation ? 'cancelled' : $row->state,
            'result_type' => $row->result_type,
            'result_id' => $row->generation !== $this->installations->current()->generation ? null : $row->result_public_id,
            'asynchronous_queue' => $this->dispatcher->asynchronous(),
        ];
    }

    private function dispatch(string $id, int $generation): bool
    {
        if (! $this->dispatcher->asynchronous()) {
            return false;
        }
        try {
            Telescope::withoutRecording(fn () => Bus::dispatch((new CaptureProbe($id, $generation))->onConnection(config('telescope-monitor.queues.connection'))->onQueue(config('telescope-monitor.queues.capture'))));
            $this->receipts->update($id, ['updated_at' => CarbonImmutable::now('UTC')]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function recover(int $limit): int
    {
        $count = 0;
        foreach ($this->receipts->pending($limit) as $id) {
            $row = $this->receipts->probe($id);
            if (! $row) {
                continue;
            }
            if ($row->expires_at->isPast()) {
                $this->receipts->update($id, ['state' => 'expired', 'completed_at' => CarbonImmutable::now('UTC')]);
            } elseif ($this->dispatch($id, $row->generation)) {
                $count++;
            }
        }

        return $count;
    }

    public function execute(string $id, int $generation): void
    {
        $receipt = $this->receipts->probe($id);
        if (! $receipt || $receipt->state !== 'pending') {
            return;
        }
        $i = $this->installations->current();
        if ($i->generation !== $generation || $i->state !== 'active' || $receipt->expires_at->isPast() || ! $this->caps->allows('manage', new Actor($receipt->actor_key, ''))) {
            $this->receipts->update($id, ['state' => 'cancelled', 'completed_at' => CarbonImmutable::now('UTC')]);

            return;
        }
        if (! Telescope::isRecording() || ! app(StoredExceptionAdapter::class)->enabled()) {
            $this->receipts->update($id, ['state' => 'recording_disabled', 'completed_at' => CarbonImmutable::now('UTC')]);

            return;
        }
        if ($this->source->hasUuid($id)) {
            Telescope::withoutRecording(fn () => app(StoredExceptionAdapter::class)->replayRows($this->source->exceptionByUuid($id), 'synthetic_test'));
            $this->receipts->transition($id, ['pending'], ['state' => 'captured']);

            return;
        }
        $exception = new \RuntimeException('Synthetic monitor capture check');
        $this->context->probeException = $exception;
        $this->context->probeId = $id;
        try {
            $entry = IncomingExceptionEntry::make($exception, [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => [],
                'telescope_monitor_origin' => ['kind' => 'job', 'job_class' => CaptureProbe::class],
            ]);
            $entry->uuid = $id;
            Telescope::recordException($entry);
            Telescope::store(app(EntriesRepository::class));
            // This is the explicit monitoring probe worker, never a host capture hook.
            if ($this->source->hasUuid($id)) {
                Telescope::withoutRecording(fn () => app(StoredExceptionAdapter::class)->replayRows($this->source->exceptionByUuid($id), 'synthetic_test'));
            }
            $this->receipts->transition($id, ['pending'], [
                'state' => $this->source->hasUuid($id) ? 'captured' : 'filtered_or_unstored',
                'completed_at' => $this->source->hasUuid($id) ? null : CarbonImmutable::now('UTC'),
            ]);
        } finally {
            $this->context->probeException = null;
            $this->context->probeId = null;
        }
    }
}
