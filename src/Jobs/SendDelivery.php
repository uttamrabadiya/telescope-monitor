<?php

namespace TelescopeMonitor\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TelescopeMonitor\Alerts\DeliverySender;

final class SendDelivery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
