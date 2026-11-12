<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Operations\OperationJournal;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Operations\RetentionSweep;
use TelescopeMonitor\Repositories\Contracts\ActivityRepository;
use TelescopeMonitor\Repositories\Contracts\AlertEventRepository;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\HealthCheckRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\MetricBucketRepository;
use TelescopeMonitor\Repositories\Contracts\OccurrenceEvidenceRepository;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Telescope\StoredEntries;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;

final class CaptureOperations
{
    public function __construct(private InstallationRepository $installations, private CaptureInboxRepository $inbox, private CaptureProcessor $processor, private CaptureDispatcher $dispatcher, private StoredEntries $source, private StoredExceptionAdapter $adapter, private CapturePolicy $policy, private OccurrenceEvidenceRepository $evidence, private MetricBucketRepository $metrics, private ActivityRepository $activity, private AlertEventRepository $events, private HealthCheckRepository $health, private CaptureObservation $observation) {}

    public function process(int $limit): array
    {
        return Telescope::withoutRecording(function () use ($limit) {
            $processed = 0;
            $ids = $this->inbox->due($limit);
            foreach ($ids as $id) {
                if ($this->processor->process($id)) {
                    $processed++;
                }
            }

            return ['examined' => count($ids), 'processed' => $processed];
        });
    }

    public function recover(int $limit): array
    {
        return app(OperationJournal::class)->run('capture_recovery', function () use ($limit) {
            $count = $this->dispatcher->recover($limit);
            $probes = app(CaptureProbes::class)->recover($limit);
            $this->observation->record('capture_recovery', $this->dispatcher->asynchronous() ? 'passed' : 'warning', $this->dispatcher->asynchronous() ? 'Recovery sweep dispatched eligible durable work.' : 'No supported asynchronous driver configured. Input remains durable; use the explicit inline command if appropriate.');

            return ['capture_jobs' => $count, 'probe_jobs' => $probes];
        });
    }

    public function import(int $limit): array
    {
        return $this->scan($limit, null, 'live');
    }

    public function reconcile(int $limit, ?int $hours = null): array
    {
        return $this->scan($limit, $hours, 'reconciliation');
    }

    private function scan(int $limit, ?int $hours, string $mode): array
    {
        return app(OperationJournal::class)->run($mode === 'live' ? 'capture_import' : 'capture_reconcile', function () use ($limit, $hours, $mode) {
            if (! $this->adapter->enabled()) {
                return ['scanned' => 0, 'buffered' => 0, 'skipped' => 0];
            }
            app(SourceStamp::class)->validate();
            try {
                $this->source->verifySource();
            } catch (CaptureFailure $failure) {
                $this->observation->record('capture_reconcile', 'failed', 'Source identity changed; explicit source-epoch review is required.');
                throw $failure;
            }
            $i = $this->installations->current();
            if ($i->state !== 'active') {
                return ['scanned' => 0, 'buffered' => 0, 'skipped' => 0, 'paused' => true];
            }
            $maximum = $this->source->maximum();
            if ($maximum < (int) $i->source_sequence_checkpoint) {
                $this->observation->record('capture_reconcile', 'failed', 'Source sequence moved backwards; explicit source-epoch review is required.');
                throw new CaptureFailure('source_epoch_review_required');
            }
            $rows = $this->source->exceptions($hours === null ? (int) $i->source_sequence_checkpoint : 0, $limit, null, $hours === null ? null : CarbonImmutable::now('UTC')->subHours($hours)->format('Y-m-d H:i:s'));
            $result = $this->adapter->replayRows($rows, $mode);
            $result['scanned'] = count($rows);
            if ($hours === null && $rows) {
                $last = (int) end($rows)['sequence'];
                Database::connection()->transaction(function () use ($i, $last) {
                    $current = $this->installations->lock();
                    if ($current->state !== 'active' || $current->source_epoch !== $i->source_epoch || $current->generation !== $i->generation) {
                        throw new CaptureFailure('installation_fenced');
                    }
                    $this->installations->checkpoint($current, $last);
                }, 5);
            }
            if ($hours === null) {
                $checks = $this->health->latest($i->id);
                $cycle = $checks['capture_overlap']['sanitized_details']['metadata'] ?? [];
                if (! isset($cycle['since'],$cycle['cursor'],$cycle['ceiling']) || $cycle['cursor'] >= $cycle['ceiling']) {
                    $cycle = [
                        'since' => CarbonImmutable::now('UTC')->subMinutes(15)->format('Y-m-d H:i:s'),
                        'cursor' => 0,
                        'ceiling' => (int) $i->source_sequence_checkpoint,
                    ];
                }
                $overlap = $this->source->exceptions((int) $cycle['cursor'], $limit, null, $cycle['since'], (int) $cycle['ceiling']);
                $other = $this->adapter->replayRows($overlap, $mode);
                foreach (['buffered', 'skipped'] as $key) {
                    $result[$key] += $other[$key];
                }
                $result['scanned'] += count($overlap);
                $cycle['cursor'] = count($overlap) < $limit && (! $overlap || ! end($overlap)['page_has_more']) ? $cycle['ceiling'] : (int) end($overlap)['sequence'];
                $this->observation->record('capture_overlap', 'passed', 'Reconciliation revisit window advanced.', $cycle);
            }
            $this->observation->record($mode === 'live' ? 'capture_import' : 'capture_reconcile', 'passed', 'Bounded source reconciliation completed.', $result);

            return $result;
        });
    }

    public function prune(int $limit): array
    {
        if (app(RetentionPolicy::class)->ready()) {
            return app(RetentionSweep::class)->run($limit);
        }

        return Telescope::withoutRecording(function () use ($limit) {
            $days = $this->policy->read()['retention']['occurrence_days'];
            $result = [
                'evidence' => $this->evidence->prune($limit),
                'inbox' => $this->inbox->prune($limit, $days),
                'metric_buckets' => $this->metrics->prune($limit),
                'activity' => $this->activity->prune($limit),
                'disabled_events' => $this->events->prune($limit),
            ];
            $this->observation->record('capture_prune', 'passed', 'Bounded capture evidence and history pruning completed.', $result);

            return $result;
        });
    }
}
