<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\LifecycleRepository;

final class ExpireSnoozesCommand extends Command
{
    protected $signature = 'telescope-monitor:expire-snoozes {--limit=100}';

    protected $description = 'Expire a bounded set of snoozes without retrospective alerts.';

    public function handle(LifecycleRepository $lifecycle, CaptureInboxRepository $inbox): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            return self::INVALID;
        }
        if (! $inbox->ready()) {
            return self::FAILURE;
        }
        try {
            $this->line((string) Telescope::withoutRecording(fn () => $lifecycle->expire($limit)));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Snooze expiry failed. Review package storage.');

            return self::FAILURE;
        }
    }
}
