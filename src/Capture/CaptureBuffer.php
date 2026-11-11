<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use TelescopeMonitor\Models\CaptureInbox;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;

final class CaptureBuffer
{
    public function __construct(private InstallationRepository $installations, private CaptureInboxRepository $inbox, private CaptureDispatcher $dispatcher, private CaptureObservation $observation) {}

    public function enqueue(array $marker, string $uuid, ?string $batch, ?int $sequence, array $payload, string $mode, string $kind = 'exception_envelope', ?string $error = null): ?CaptureInbox
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > 131072) {
            throw new CaptureFailure('envelope_too_large');
        }

        return Database::connection()->transaction(function () use ($marker, $uuid, $batch, $sequence, $json, $mode, $kind, $error) {
            $i = $this->installations->fence();
            if ($i->state !== 'active' || $i->generation !== $marker['generation'] || $i->source_epoch !== $marker['source_epoch']) {
                return null;
            }
            if (($i->purged_through_at && CarbonImmutable::parse($marker['occurred_at'], 'UTC') <= $i->purged_through_at) || ($sequence !== null && $sequence <= (int) $i->source_sequence_floor)) {
                return null;
            }
            $row = $this->inbox->enqueue([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i->id,
                'generation' => $i->generation,
                'source_epoch' => $i->source_epoch,
                'capture_key' => hash('sha256', $kind.':'.$i->source_epoch.':'.$uuid),
                'kind' => $kind,
                'mode' => $mode,
                'source_uuid' => $kind === 'exception_envelope' ? $uuid : null,
                'batch_uuid' => $batch,
                'source_sequence' => $kind === 'batch_reference' ? 0 : $sequence,
                'environment' => $marker['environment'],
                'occurred_at' => $marker['occurred_at'],
                'time_precision' => $marker['time_precision'] ?? 'microsecond',
                'received_at' => CarbonImmutable::now('UTC'),
                'grouping_version' => $marker['grouping_version'] ?? 1,
                'fingerprint_hash' => $marker['fingerprint_hash'] ?? null,
                'privacy_revision' => $marker['privacy_revision'] ?? $i->config_revision,
                'payload_ciphertext' => $error ? null : Crypt::encryptString($json),
                'plaintext_bytes' => strlen($json),
                'payload_digest' => hash('sha256', $json),
                'state' => $error ? 'failed' : 'pending',
                'last_error_code' => $error,
                'completed_at' => $error ? CarbonImmutable::now('UTC') : null,
                'eligible_at' => CarbonImmutable::now('UTC'),
            ]);
            if ($row->wasRecentlyCreated) {
                Database::connection()->afterCommit(function () use ($row, $error) {
                    $this->observation->record('capture_received', 'passed', 'An accepted source exception was durably buffered.', ['generation' => $row->generation]);
                    if (! $error) {
                        $this->dispatcher->dispatch($row->id, $row->generation);
                    }
                });
            }

            return $row;
        }, 5);
    }
}
