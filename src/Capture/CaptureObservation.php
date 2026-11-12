<?php

namespace TelescopeMonitor\Capture;

use Laravel\Telescope\Telescope;
use TelescopeMonitor\Repositories\Contracts\HealthCheckRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use Throwable;

final class CaptureObservation
{
    public function __construct(private InstallationRepository $installations, private HealthCheckRepository $health) {}

    public function start(string $key, array $metadata = []): void
    {
        $this->record($key, 'running', 'A processing attempt started; completion is not yet observed.', $metadata + ['phase' => 'started']);
    }

    public function record(string $key, string $state, string $detail, array $metadata = []): void
    {
        try {
            $i = $this->installations->current();
            if (isset($metadata['generation']) && $metadata['generation'] !== $i->generation) {
                return;
            }
            Telescope::withoutRecording(fn () => $this->health->record($i->id, [
                [
                    'phase' => $metadata['phase'] ?? 'completed',
                    'key' => $key,
                    'label' => match ($key) {
                        'capture_worker' => 'Capture processing',
                        'capture_import' => 'Telescope importer',
                        'capture_recovery' => 'Capture recovery',
                        'capture_prune' => 'Capture pruning',
                        'delivery_worker' => 'Email delivery processing',
                        'delivery_recovery' => 'Email delivery recovery',
                        'delivery_prune' => 'Email delivery pruning',
                        'delivery_dispatch' => 'Email dispatch',
                        default => 'Capture ingestion',
                    },
                    'state' => $state,
                    'kind' => 'observation',
                    'detail' => $detail,
                    'metadata' => $metadata + ['generation' => $i->generation],
                ],
            ]));
        } catch (Throwable) {
        }
    }
}
