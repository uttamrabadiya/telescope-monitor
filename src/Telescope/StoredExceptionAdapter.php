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
use TelescopeMonitor\Capture\CaptureObservation;
use TelescopeMonitor\Capture\CapturePolicy;
use TelescopeMonitor\Capture\Sanitizer;
use TelescopeMonitor\Capture\SourceMarker;
use TelescopeMonitor\Capture\SourceSnapshot;
use TelescopeMonitor\Models\ActionReceipt;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use Throwable;

final class StoredExceptionAdapter
{
    public function __construct(private CaptureInboxRepository $inbox, private InstallationRepository $installations, private SourceMarker $markers, private CaptureBuffer $buffer, private CapturePolicy $policy, private Sanitizer $sanitizer, private CaptureContext $context, private StoredEntries $source, private CaptureObservation $observation) {}

    public function enabled(): bool
    {
        $watcher = config('telescope.watchers.'.ExceptionWatcher::class, false);

        return config('exception-monitor.enabled') && config('exception-monitor.capture.enabled', true) && config('telescope.enabled') && (is_array($watcher) ? ($watcher['enabled'] ?? true) : (bool) $watcher);
    }

    public function tags(IncomingEntry $entry): array
    {
        try {
            if (! $entry->isException() || ! $this->enabled() || ! $entry->isReportableException() || $this->internal($entry) || ! $this->inbox->ready()) {
                return [];
            }
            $entry->content['exception_monitor_source'] = app(SourceSnapshot::class)->capture();
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
            $this->source->verifySource();
            $i = $this->installations->current();
            if ($this->context->probeId && ActionReceipt::query()->where('public_id', $this->context->probeId)->where('generation', $i->generation)->whereIn('state', ['pending', 'captured'])->doesntExist()) {
                return [];
            }
            if (! in_array($i->state, ['active', 'draining', 'purging'], true)) {
                return [];
            }

            return $this->markers->tags($i, $entry->uuid, $entry->recordedAt, $entry->content, $this->context->probeException === ($entry->exception ?? null) ? $this->context->probeId : null);
        } catch (Throwable) {
            $this->observation->record('capture_ingestion', 'failed', 'Capture metadata could not be prepared. Review schema, grouping policy and encryption-key availability.');

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
        try {
            if (! $this->enabled() || ! $this->inbox->ready()) {
                return true;
            }
            $exceptions = array_values(array_filter($entries, fn ($e) => $e->type === 'exception' && count(array_filter($e->tags, fn ($tag) => is_string($tag) && str_starts_with($tag, 'em2c|'))) === 1));
            if (! $exceptions) {
                return true;
            }
            $this->source->verifySource();
            $i = $this->installations->current();
            if ($this->source->maximum() < (int) $i->source_sequence_checkpoint) {
                $this->observation->record('capture_ingestion', 'failed', 'Telescope source sequence moved backwards. Explicit source-epoch review is required.');

                return true;
            }
            if ($i->state !== 'active') {
                return true;
            }
            $policy = $this->policy->read();
            $batchData = [];
            $sourceBytes = 0;
            $contextTruncated = false;
            $entrypoints = array_values(array_filter($entries, fn ($e) => in_array($e->type, ['request', 'job', 'command'], true)));
            $related = array_values(array_filter($entries, fn ($e) => in_array($e->type, ['log', 'query'], true)));
            foreach (array_merge($entrypoints, $related) as $entry) {
                if (! in_array($entry->type, ['request', 'job', 'command', 'log', 'query'], true)) {
                    continue;
                }
                if (count($batchData) >= 100 || (is_string($entry->content) && ($sourceBytes + strlen($entry->content)) > 1048576)) {
                    $contextTruncated = true;

                    continue;
                }
                $sourceBytes += is_string($entry->content) ? strlen($entry->content) : 0;
                $content = is_string($entry->content) ? json_decode($entry->content, true) : $entry->content;
                $batchData[] = ['type' => $entry->type, 'content' => $content];
            }
            $context = $this->sanitizer->batch($batchData, $policy);
            if ($contextTruncated) {
                $context['coverage']['timeline'] = 'truncated';
            }
            $limit = max(1, min(1000, (int) config('exception-monitor.capture.inline_limit', 100)));
            $first = array_slice($exceptions, 0, $limit);
            $sequences = $this->source->sequences(array_map(fn ($e) => $e->uuid, $first));
            $used = 0;
            $defer = count($exceptions) > $limit;
            foreach ($first as $entry) {
                $marker = $this->markers->read($i, $entry->uuid, $entry->tags);
                if (! $marker) {
                    continue;
                }
                if (is_string($entry->content) && $sourceBytes + strlen($entry->content) > 2097152) {
                    $defer = true;

                    continue;
                }
                $sourceBytes += is_string($entry->content) ? strlen($entry->content) : 0;
                $content = is_string($entry->content) ? json_decode($entry->content, true) : $entry->content;
                if (! is_array($content)) {
                    continue;
                }
                $marker['policy'] = $policy;
                $trace = isset($entry->exception) ? $entry->exception->getTrace() : null;
                $payload = $this->sanitizer->envelope($content, $marker, $context, $entry->tags, $trace);
                $bytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
                if ($used + $bytes > 2097152) {
                    $defer = true;
                    break;
                }
                $used += $bytes;
                $this->buffer->enqueue($payload['marker'], $entry->uuid, $batch, isset($sequences[$entry->uuid]) ? (int) $sequences[$entry->uuid] : null, $payload, $payload['marker']['probe_id'] ? 'synthetic_test' : 'live');
            }
            if ($defer) {
                $this->buffer->enqueue([
                    'generation' => $i->generation,
                    'source_epoch' => $i->source_epoch,
                    'environment' => app()->environment(),
                    'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                ], $batch, $batch, null, ['version' => 1, 'batch_uuid' => $batch, 'expected_count' => count($exceptions), 'scanned_count' => 0], 'live', 'batch_reference');
            }
            $this->observation->record('capture_ingestion', 'passed', 'Accepted Telescope batch buffering completed. Deferred source batches still require processing.');
        } catch (Throwable) {
            $this->observation->record('capture_ingestion', 'failed', 'An accepted Telescope batch could not be fully buffered. Recovery requires retained, marked source entries.');
        }

        return true;
    }

    public function replayRows(array $rows, string $mode): array
    {
        $this->source->verifySource();
        $result = ['buffered' => 0, 'skipped' => 0];
        $i = $this->installations->current();
        $policy = $this->policy->read();
        $contexts = [];
        foreach ($rows as $row) {
            $marker = $this->markers->read($i, $row['uuid'], $row['tags']);
            if (! $marker) {
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
            $payload = $this->sanitizer->envelope($row['content'], $marker, $contexts[$batch], $row['tags']);
            $item = $this->buffer->enqueue($payload['marker'], $row['uuid'], $batch, $row['sequence'], $payload, $marker['probe_id'] ? 'synthetic_test' : $mode);
            if ($item && $item->wasRecentlyCreated) {
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
