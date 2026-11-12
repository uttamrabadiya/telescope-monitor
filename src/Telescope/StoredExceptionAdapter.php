<?php

namespace TelescopeMonitor\Telescope;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Routing\Route;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\ExceptionWatcher;
use Laravel\Telescope\Watchers\RequestWatcher;
use TelescopeMonitor\Capture\CaptureBuffer;
use TelescopeMonitor\Capture\CaptureContext;
use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Capture\Sanitizer;
use TelescopeMonitor\Capture\SourceMarker;
use TelescopeMonitor\Capture\SourceSnapshot;
use TelescopeMonitor\Capture\SourceStamp;
use TelescopeMonitor\Capture\CaptureFailure;
use TelescopeMonitor\Models\ActionReceipt;
use Throwable;

final class StoredExceptionAdapter
{
    public function __construct(private SourceMarker $markers, private CaptureBuffer $buffer, private CapturePolicy $policy, private Sanitizer $sanitizer, private CaptureContext $context, private StoredEntries $source) {}

    public function enabled(): bool
    {
        $watcher = config('telescope.watchers.'.ExceptionWatcher::class, false);

        return config('exception-monitor.enabled') && config('exception-monitor.capture.enabled', true) && config('telescope.enabled') && (is_array($watcher) ? ($watcher['enabled'] ?? true) : (bool) $watcher);
    }

    public function tags(IncomingEntry $entry): array
    {
        try {
            if (! $entry->isException() || ! $this->enabled() || ! $entry->isReportableException() || $this->internal($entry)) {
                return [];
            }
            $exception = $entry->exception ?? null;
            if (! $exception instanceof Throwable) {
                return [];
            }
            unset($entry->content['exception_monitor_trace'], $entry->content['exception_monitor_capture'], $entry->content['exception_monitor_source'], $entry->content['exception_monitor_origin'], $entry->content['exception_monitor_route']);
            $entry->content['exception_monitor_source'] = app(SourceSnapshot::class)->capture();
            $entry->content['exception_monitor_trace'] = array_map(function ($frame) {
                $out = [];
                foreach (['file' => 2048, 'class' => 512, 'function' => 191] as $key => $limit) {
                    if (is_string($frame[$key] ?? null)) {
                        $value = $key === 'file' ? app(\TelescopeMonitor\Capture\Fingerprint::class)->file($frame[$key]) : $frame[$key];
                        $out[$key] = mb_strcut($value, 0, $limit, 'UTF-8');
                    }
                }
                $out['line'] = max(0, (int) ($frame['line'] ?? 0));
                return $out;
            }, array_slice($exception->getTrace(), 0, 100));
            $requestWatcher = config('telescope.watchers.'.RequestWatcher::class, false);
            $requestContext = is_array($requestWatcher) ? ($requestWatcher['enabled'] ?? true) : (bool) $requestWatcher;
            if ($requestContext && ! app()->runningInConsole() && app()->bound('request') && app('request')->route() instanceof Route) {
                $route = app('request')->route();
                $entry->content['exception_monitor_route'] = ['name' => $route->getName(), 'template' => '/'.ltrim($route->uri(), '/')];
            }
            if ($this->context->job) {
                $entry->content['exception_monitor_origin'] = $this->context->job;
            } elseif ($this->context->failedJobException === ($entry->exception ?? null) && $this->context->failedJobOrigin) {
                $entry->content['exception_monitor_origin'] = $this->context->failedJobOrigin;
            } elseif ($this->context->command) {
                $entry->content['exception_monitor_origin'] = ['kind' => 'command', 'command' => $this->context->command];
            }
            return app(SourceStamp::class)->tags($entry->uuid, $entry->recordedAt, $entry->content, $this->context->probeException === ($entry->exception ?? null) ? $this->context->probeId : null);
        } catch (Throwable) {
            return [];
        }
    }

    private function internal(IncomingEntry $entry): bool
    {
        $exception = $entry->exception ?? null;
        if ($exception === $this->context->probeException && $this->context->probeId) {
            return false;
        }
        if (! $exception instanceof Throwable) {
            return true;
        }
        if ($exception instanceof MaxAttemptsExceededException && str_starts_with($exception->job?->resolveName() ?? '', 'TelescopeMonitor\\Jobs\\')) {
            return true;
        }
        $root = dirname(__DIR__).DIRECTORY_SEPARATOR;
        foreach (array_merge([['file' => $exception->getFile()]], $exception->getTrace()) as $frame) {
            if (str_starts_with($frame['file'] ?? '', $root)) {
                return true;
            }
        }

        return false;
    }

    public function stored(array $entries, string $batch): bool
    {
        // Telescope is the durable source. Only the separate importer may buffer evidence.
        return true;
    }

    public function replayRows(array $rows, string $mode): array
    {
        $i = $this->source->verifySource();
        $result = ['buffered' => 0, 'skipped' => 0];
        $policy = $this->policy->read();
        $contexts = [];
        foreach ($rows as $row) {
            $marker = $this->markers->read($i, $row['uuid'], $row['tags'], $row['content']);
            if (! $marker) {
                $result['skipped']++;

                continue;
            }
            if ($i->state !== 'active') {
                throw new CaptureFailure('installation_fenced');
            }
            if ($row['sequence'] <= $i->source_sequence_floor || ($i->purged_through_at && (CarbonImmutable::parse($marker['occurred_at'], 'UTC') <= $i->purged_through_at || (isset($marker['captured_at']) && CarbonImmutable::parse($marker['captured_at'], 'UTC') <= $i->purged_through_at)))) {
                $result['skipped']++;
                continue;
            }
            if ($marker['probe_id'] && ($marker['probe_id'] !== $row['uuid'] || ! ActionReceipt::query()->where('public_id', $marker['probe_id'])->where('installation_id', $i->id)->where('generation', $i->generation)->whereIn('state', ['pending', 'captured'])->exists())) {
                $result['skipped']++;
                continue;
            }
            if (! is_array($row['content'])) {
                $this->buffer->enqueue($marker, $row['uuid'], $row['batch_uuid'], $row['sequence'], [], $mode, 'exception_envelope', 'source_payload_unavailable');
                $result['skipped']++;

                continue;
            }
            $batch = $row['batch_uuid'];
            if (! isset($contexts[$batch])) {
                $contexts[$batch] = $this->sanitizer->batch($this->source->context($batch), $policy);
            }
            $marker['policy'] = $policy;
            $content = $row['content'];
            if (isset($content['exception_monitor_capture']['grouping_file'])) {
                $content['file'] = $content['exception_monitor_capture']['grouping_file'];
            }
            $payload = $this->sanitizer->envelope($content, $marker, $contexts[$batch], $row['tags'], $content['exception_monitor_trace'] ?? null);
            $item = $this->buffer->enqueue($payload['marker'], $row['uuid'], $batch, $row['sequence'], $payload, $marker['probe_id'] ? 'synthetic_test' : ($mode === 'live' && ! isset($row['content']['exception_monitor_capture']) ? 'reconciliation' : $mode));
            if (! $item) {
                throw new CaptureFailure('installation_fenced');
            }
            if ($item->wasRecentlyCreated) {
                $result['buffered']++;
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    public function failedJob(JobFailed $event): void
    {
        try {
            if (! config('exception-monitor.capture.bridge_jobs', true) || ! $this->enabled() || ! Telescope::isRecording() || $event->connectionName === 'sync' || str_starts_with($event->job->resolveName(), 'TelescopeMonitor\\Jobs\\')) {
                return;
            }
            foreach (Telescope::$entriesQueue as $entry) {
                if (($entry->exception ?? null) === $event->exception) {
                    return;
                }
            }
            $e = $event->exception;
            $entry = IncomingExceptionEntry::make($e, [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_map(fn ($f) => array_intersect_key($f, array_flip(['file', 'line', 'class', 'function'])), array_slice($e->getTrace(), 0, 100)),
                'exception_monitor_origin' => ['kind' => 'job', 'job_class' => $event->job->resolveName()],
            ]);
            if ($entry->isReportableException()) {
                Telescope::recordException($entry);
            }
        } catch (Throwable) {
        }
    }
}
