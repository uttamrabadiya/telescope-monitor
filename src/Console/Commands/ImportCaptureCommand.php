<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;

final class ImportCaptureCommand extends Command
{
    protected $signature = 'telescope-monitor:import {--limit=100} {--process : Also process buffered evidence in this monitoring process} {--watch : Run as a separate supervised importer} {--sleep=2 : Seconds between bounded passes}';

    protected $description = 'Import accepted, deployment-signed Telescope exceptions outside host execution.';

    public function handle(CaptureOperations $operations, CaptureInboxRepository $inbox): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $sleep = filter_var($this->option('sleep'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000 || $sleep === false || $sleep < 1 || $sleep > 30) {
            $this->error('Use --limit=1..10000 and --sleep=1..30.');
            return self::INVALID;
        }
        $running = true;
        if (extension_loaded('pcntl') && $this->option('watch')) {
            $this->trap([SIGINT, SIGTERM], function () use (&$running) {
                $running = false;
            });
        }
        do {
            try {
                if (! $inbox->ready()) {
                    $this->error('Publish and run the monitoring migrations first.');
                    return self::FAILURE;
                }
                $result = $operations->import($limit);
                if ($this->option('process')) {
                    $result['processing'] = $operations->process($limit);
                }
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                $this->error('Telescope import failed. Check source identity, signing keys, grouping version and monitoring storage. No raw evidence was logged.');
                if (! $this->option('watch')) {
                    return self::FAILURE;
                }
            }
            if (! $this->option('watch') || ! $running) {
                break;
            }
            sleep($sleep);
        } while ($running);

        return self::SUCCESS;
    }
}
