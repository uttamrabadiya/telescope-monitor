<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Capture\CaptureFailure;
use TelescopeMonitor\Capture\HistoricalBackfill;

final class BackfillCommand extends Command
{
    protected $signature = 'exception-monitor:backfill {--environment= : Required original environment for legacy records} {--after=0 : Exclusive source sequence cursor} {--through= : Inclusive fixed source sequence ceiling} {--limit=100 : Maximum rows per page, 1–1000} {--dry-run : Inspect candidates without writing monitoring data}';

    protected $description = 'Import one bounded page of unmarked Telescope exceptions as sanitized history, without alerts.';

    public function handle(HistoricalBackfill $backfill): int
    {
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $through = $this->option('through') === null ? null : filter_var($this->option('through'), FILTER_VALIDATE_INT);
        if ($after === false || $limit === false || $through === false || $after < 0 || $limit < 1 || $limit > 1000 || ($through !== null && $through < $after) || ! is_string($this->option('environment')) || $this->option('environment') === '') {
            $this->error('Use --environment=<original-environment>, --limit=1..1000 and nonnegative sequence cursors.');

            return self::INVALID;
        }
        try {
            $result = $backfill->run($this->option('environment'), $after, $limit, $through, (bool) $this->option('dry-run'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (CaptureFailure $e) {
            $safe = in_array($e->getMessage(), ['invalid_source_timezone', 'invalid_backfill_options', 'backfill_unavailable', 'backfill_requires_active_installation', 'source_epoch_review_required', 'installation_fenced'], true) ? $e->getMessage() : 'backfill_processing_failed';
            $this->error('Backfill stopped: '.$safe.'. Existing committed imports can be replayed safely.');

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Backfill failed. Review storage, source epoch, grouping and encryption configuration. Repeat the last page safely; no raw payload was logged.');

            return self::FAILURE;
        }
    }
}
