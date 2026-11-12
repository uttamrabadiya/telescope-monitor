<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use TelescopeMonitor\Capture\CaptureFailure;
use TelescopeMonitor\Capture\HistoricalBackfill;

final class BackfillCommand extends Command
{
    protected $signature = 'exception-monitor:backfill {--environment= : Required original environment for legacy records} {--after=0 : Exclusive source sequence cursor} {--through= : Inclusive fixed source sequence ceiling} {--limit=100 : Maximum rows per page, 1–1000} {--dry-run : Inspect candidates without writing monitoring data} {--json : Output machine-readable JSON}';

    protected $description = 'Import one bounded page of unmarked Telescope exceptions as sanitized history, without alerts.';

    public function handle(HistoricalBackfill $backfill): int
    {
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $through = $this->option('through') === null ? null : filter_var($this->option('through'), FILTER_VALIDATE_INT);
        if ($after === false || $limit === false || $through === false || $after < 0 || $limit < 1 || $limit > 1000 || ($through !== null && $through < $after) || ! is_string($this->option('environment')) || $this->option('environment') === '') {
            return $this->failure('invalid_backfill_options', 'Use --environment=<original-environment>, --limit=1..1000 and nonnegative sequence cursors.', self::INVALID);
        }
        try {
            $result = $backfill->run($this->option('environment'), $after, $limit, $through, (bool) $this->option('dry-run'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } else {
                $this->summary($result, $after, $limit);
            }

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (CaptureFailure $e) {
            $safe = in_array($e->getMessage(), [
                'invalid_source_timezone',
                'invalid_backfill_options',
                'backfill_unavailable',
                'backfill_requires_active_installation',
                'source_epoch_review_required',
                'installation_fenced',
            ], true) ? $e->getMessage() : 'backfill_processing_failed';

            return $this->failure($safe, 'Backfill stopped: '.$safe.'. Existing committed imports can be replayed safely.');
        } catch (\Throwable) {
            return $this->failure('backfill_failed', 'Backfill failed. Review storage, source epoch, grouping and encryption configuration. Repeat the last page safely; no raw payload was logged.');
        }
    }

    private function summary(array $result, int $after, int $limit): void
    {
        $dry = $result['dry_run'];
        $this->components->info($dry ? 'Telescope backfill — dry run' : 'Telescope backfill — page processed');
        $this->line('Source environment: '.OutputFormatter::escape($result['environment']));
        if ($dry) {
            $this->line('No monitoring data was written.');
        }
        $rows = [
            ['Scanned exceptions', number_format($result['scanned'])],
            ['Eligible candidates', number_format($result['eligible'])],
            ['Already imported', number_format($result['duplicates'])],
        ];
        if (! $dry) {
            $rows[] = ['Imported this run', number_format($result['imported'])];
            $rows[] = ['Pending processing', number_format($result['pending'])];
            $rows[] = ['Failed', number_format($result['failed'])];
        }
        $skipped = $result['skipped_marked'] + $result['skipped_invalid'] + $result['skipped_fenced'];
        $rows[] = ['Skipped', number_format($skipped)];
        $this->table(['Result', 'Count'], $rows);
        foreach ([
            'skipped_marked' => 'Package-marked (use normal recovery)',
            'skipped_invalid' => 'Malformed, oversized or future-dated',
            'skipped_fenced' => 'Protected by purge fences',
        ] as $key => $label) {
            if ($result[$key]) {
                $this->line('  '.$label.': '.number_format($result[$key]));
            }
        }
        $this->line('Last scanned sequence: '.number_format($result['next_after']).' | Snapshot ceiling: '.number_format($result['through']));
        $this->line('Sequence IDs are resume cursors, not exception totals or a completion percentage.');
        if ($dry && $result['eligible'] > 0) {
            $this->newLine();
            $this->line('To import this page:');
            $this->line('  '.OutputFormatter::escape($this->resumeCommand($result, $after, $limit, false)));
        }
        if ($result['failed'] > 0) {
            $this->components->warn('Review failed capture items before continuing. Existing imports remain committed.');
        } elseif ($result['has_more']) {
            $this->newLine();
            $this->line($dry ? 'More exceptions remain. To preview the next page:' : 'More exceptions remain. Continue with:');
            $this->line('  '.OutputFormatter::escape($this->resumeCommand($result, $result['next_after'], $limit, $dry)));
        } else {
            $this->newLine();
            $this->line($dry ? 'No more exceptions in this source snapshot.' : 'Source snapshot scanned.');
        }
        if ($result['pending'] > 0) {
            $this->components->warn('Some buffered items still need processing. Keep the capture worker and recovery schedule running.');
        }
    }

    private function resumeCommand(array $result, int $after, int $limit, bool $dry): string
    {
        $environment = preg_match('/^[A-Za-z0-9_.-]+$/D', $result['environment']) ? $result['environment'] : escapeshellarg($result['environment']);

        $command = 'php artisan exception-monitor:backfill --environment='.$environment.' --after='.$after.' --through='.$result['through'].' --limit='.$limit.($dry ? ' --dry-run' : '');
        $runtime = $this->option('env');
        if (is_string($runtime) && $runtime !== '') {
            $command .= ' --env='.(preg_match('/^[A-Za-z0-9_.-]+$/D', $runtime) ? $runtime : escapeshellarg($runtime));
        }

        return $command;
    }

    private function failure(string $code, string $message, int $status = self::FAILURE): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return $status;
    }
}
