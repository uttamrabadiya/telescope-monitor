<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;

final class ReconcileCaptureCommand extends Command
{
    protected $signature = 'exception-monitor:reconcile {--limit=100} {--hours=}';

    protected $description = 'Replay marked Telescope exceptions without retroactive alerts.';

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
        $hours = $this->option('hours') === null ? null : filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours !== null && ($hours === false || $hours < 1 || $hours > 720)) {
            $this->error('Use --hours=1..720.');

            return self::INVALID;
        }
        try {
            $result = $operations->reconcile($limit, $hours);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Capture operation failed. Review storage, encryption keys and source-epoch diagnostics. No raw payload was logged.');

            return self::FAILURE;
        }
    }
}
