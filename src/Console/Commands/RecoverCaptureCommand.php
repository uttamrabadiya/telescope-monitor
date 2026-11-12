<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;

final class RecoverCaptureCommand extends Command
{
    protected $signature = 'telescope-monitor:recover {--limit=100}';

    protected $description = 'Dispatch due capture input and pending probes to the configured asynchronous queue.';

    public function handle(CaptureOperations $operations, CaptureInboxRepository $inbox): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            $this->error('Use --limit=1..10000.');

            return self::INVALID;
        }
        if (! $inbox->ready()) {
            $this->error('Publish and run the capture migrations first.');

            return self::FAILURE;
        }
        try {
            $result = $operations->recover($limit);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Capture operation failed. Review storage, encryption keys and source-epoch diagnostics. No raw payload was logged.');

            return self::FAILURE;
        }
    }
}
