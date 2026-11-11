<?php

namespace TelescopeMonitor\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use TelescopeMonitor\Alerts\DeliverySender;

final class SendDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $deliveryId, public int $generation) {}

    public function handle(DeliverySender $sender): void
    {
        try {
            $sender->send($this->deliveryId, $this->generation);
        } catch (\Throwable) {/* Durable claim is recovered without reporting provider exceptions. */
        }
    }
}
