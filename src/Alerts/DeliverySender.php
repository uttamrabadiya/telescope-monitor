<?php

namespace TelescopeMonitor\Alerts;

use Laravel\Telescope\Telescope;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Capture\CaptureObservation;
use TelescopeMonitor\Data\Actor;
use TelescopeMonitor\Repositories\Contracts\DeliveryLedger;

final class DeliverySender
{
    public function __construct(private DeliveryLedger $ledger, private NotificationProvider $provider, private Capabilities $caps, private CaptureObservation $observation) {}

    public function send(int $id, ?int $generation = null): bool
    {
        return Telescope::withoutRecording(function () use ($id, $generation) {
            $routing = $this->ledger->routing($id);
            if (! $routing) {
                return false;
            }
            $allowed = ! $routing['actor_required'] || ($routing['actor_key'] !== null && $this->caps->allows('view', new Actor($routing['actor_key'], '')) && $this->caps->allows($routing['action'] === 'connection_test' || str_starts_with($routing['action'], 'report_') ? 'manage' : 'operate', new Actor($routing['actor_key'], '')));
            $claim = $this->ledger->claim($id, $generation, $allowed);
            if (! $claim) {
                return false;
            }
            $this->observation->start('delivery_worker', ['generation' => $claim['generation']]);
            try {
                $outcome = $this->provider->send($claim['envelope'], $claim['target']);
            } catch (\Throwable) {
                $outcome = Outcome::retryable();
            }
            if (! $this->ledger->finish($id, $claim['token'], $outcome)) {
                return false;
            }
            $this->observation->record('delivery_worker', $outcome->kind === 'accepted' ? 'passed' : 'warning', $outcome->kind === 'accepted' ? 'The host mail transport accepted a delivery. This does not prove inbox receipt.' : 'A provider attempt completed with a safe classified outcome.', ['generation' => $claim['generation'], 'attempt_failed' => $outcome->kind !== 'accepted']);

            return true;
        });
    }
}
