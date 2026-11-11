<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Models\CaptureInbox;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\ActionReceiptRepository;
use TelescopeMonitor\Repositories\Contracts\ActivityRepository;
use TelescopeMonitor\Repositories\Contracts\AlertEventRepository;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\EvidenceTagRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\IssueRepository;
use TelescopeMonitor\Repositories\Contracts\MetricBucketRepository;
use TelescopeMonitor\Repositories\Contracts\OccurrenceEvidenceRepository;
use TelescopeMonitor\Repositories\Contracts\OccurrenceRepository;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Telescope\StoredEntries;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;
use Throwable;

final class CaptureProcessor
{
    public function __construct(private InstallationRepository $installations, private CaptureInboxRepository $inbox, private EnvelopeCodec $codec, private CapturePolicy $policy, private Sanitizer $sanitizer, private IssueRepository $issues, private OccurrenceRepository $occurrences, private OccurrenceEvidenceRepository $evidence, private EvidenceTagRepository $tags, private MetricBucketRepository $metrics, private ActivityRepository $activity, private AlertEventRepository $events, private ActionReceiptRepository $receipts, private CaptureObservation $observation) {}

    public function process(int $id, ?int $expectedGeneration = null): bool
    {
        return Telescope::withoutRecording(function () use ($id, $expectedGeneration) {
            $claimed = Database::connection()->transaction(function () use ($id, $expectedGeneration) {
                $i = $this->installations->fence();
                $item = $this->inbox->lock($id);
                if (! $item || in_array($item->state, ['completed', 'failed', 'cancelled'], true)) {
                    return null;
                }
                if ($item->state === 'processing' && $item->lease_until?->isFuture()) {
                    return null;
                }
                if ($item->eligible_at->isFuture()) {
                    return null;
                }
                if ($i->state !== 'active' || $item->generation !== $i->generation || $item->source_epoch !== $i->source_epoch || ($expectedGeneration !== null && $expectedGeneration !== $i->generation)) {
                    $item->state = 'cancelled';
                    $item->last_error_code = 'installation_fenced';
                    $item->completed_at = CarbonImmutable::now('UTC');
                    $this->inbox->save($item);

                    return null;
                }
                if ($item->received_at < CarbonImmutable::now('UTC')->subDays($this->policy->read()['retention']['occurrence_days'])) {
                    $item->state = 'failed';
                    $item->last_error_code = 'buffer_retention_expired';
                    $item->payload_ciphertext = null;
                    $item->completed_at = CarbonImmutable::now('UTC');
                    $item->claim_token = null;
                    $item->lease_until = null;
                    $this->inbox->save($item);

                    return null;
                }
                if ($item->attempt_count >= 5) {
                    $item->state = 'failed';
                    $item->last_error_code = 'processing_attempts_exhausted';
                    $item->completed_at = CarbonImmutable::now('UTC');
                    $this->inbox->save($item);

                    return null;
                }
                $item->attempt_count++;
                $item->state = 'processing';
                $item->claim_token = (string) Str::uuid();
                $item->lease_until = CarbonImmutable::now('UTC')->addSeconds(120);
                $this->inbox->save($item);

                return $item;
            }, 5);
            if (! $claimed) {
                return false;
            }
            $this->observation->start('capture_worker', ['generation' => $claimed->generation]);
            try {
                $payload = $this->codec->decode($claimed);
                if ($claimed->kind === 'batch_reference') {
                    $completed = $this->expand($claimed, $payload);
                } else {
                    $completed = $this->ingest($claimed, $payload);
                }
                if (! $completed) {
                    return false;
                }
                $this->observation->record('capture_worker', 'passed', 'Capture processing completed.', ['generation' => $claimed->generation]);

                return true;
            } catch (CaptureFailure $e) {
                $this->failed($claimed->id, $claimed->claim_token, $e->getMessage(), false);
                $this->observation->record('capture_worker', 'failed', 'A capture processing attempt failed. Inspect its safe diagnostic code.', ['generation' => $claimed->generation]);
            } catch (Throwable) {
                $this->failed($claimed->id, $claimed->claim_token, 'processing_failed', true);
                $this->observation->record('capture_worker', 'failed', 'A capture processing attempt failed. Inspect its safe diagnostic code.', ['generation' => $claimed->generation]);
            }

            return false;
        });
    }

    private function ingest(CaptureInbox $claimed, array $payload): bool
    {
        return Database::connection()->transaction(function () use ($claimed, $payload) {
            $i = $this->installations->fence();
            $item = $this->inbox->lock($claimed->id);
            if (! $item || $item->claim_token !== $claimed->claim_token || $item->state !== 'processing') {
                return false;
            }
            if ($i->generation !== $item->generation || $i->source_epoch !== $item->source_epoch || $i->state !== 'active' || ($i->purged_through_at && $item->occurred_at <= $i->purged_through_at) || ($item->source_sequence !== null && $item->source_sequence <= (int) $i->source_sequence_floor)) {
                $item->state = 'cancelled';
                $item->last_error_code = 'installation_fenced';
                $item->completed_at = CarbonImmutable::now('UTC');
                $this->inbox->save($item);

                return false;
            }
            $policy = $this->policy->read();
            $payload = $this->sanitizer->restrict($payload, $policy);
            $e = $payload['exception'];
            $test = $item->mode === 'synthetic_test';
            $historical = $item->mode === 'historical_import';
            $issue = $this->issues->forCapture([
                'installation_id' => $i->id,
                'environment' => $item->environment,
                'is_test' => $test,
                'grouping_version' => $item->grouping_version,
                'fingerprint_hash' => $item->fingerprint_hash,
                'exception_class' => $e['class'],
                'message' => $e['message'],
                'file' => $e['file'],
                'line' => $e['line'],
                'first_seen_at' => $item->occurred_at,
                'last_seen_at' => $item->occurred_at,
            ]);
            $occurrence = $this->occurrences->record([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i->id,
                'issue_id' => $issue->id,
                'source_epoch' => $item->source_epoch,
                'source_uuid' => $item->source_uuid,
                'source_sequence' => $item->source_sequence,
                'batch_uuid' => $item->batch_uuid,
                'occurred_at' => $item->occurred_at,
                'time_precision' => $item->time_precision,
                'received_at' => $item->received_at,
                'origin' => $payload['origin'],
                'mode' => $item->mode,
                'is_test' => $test,
            ]);
            if ($occurrence->wasRecentlyCreated) {
                $first = $issue->lifetime_count === 0;
                $oldFirst = $issue->first_seen_at;
                $regression = false;
                $issue->lifetime_count++;
                $issue->first_seen_at = $issue->first_seen_at->min($item->occurred_at);
                if ($item->occurred_at >= $issue->last_seen_at) {
                    $issue->message = $e['message'];
                    $issue->last_seen_at = $item->occurred_at;
                }
                if (! $historical && $issue->status === 'snoozed' && $issue->snoozed_until && $issue->snoozed_until <= CarbonImmutable::now('UTC')) {
                    $issue->notification_cutoff_at = $issue->notification_cutoff_at?->max($issue->snoozed_until) ?? $issue->snoozed_until;
                    $issue->status = 'unresolved';
                    $issue->snoozed_at = null;
                    $issue->snoozed_until = null;
                    $issue->state_version++;
                    $this->activity->record($issue, 'error.snooze_expired', $occurrence->public_id, CarbonImmutable::now('UTC'), $item->mode);
                }
                if (! $historical && $issue->status === 'resolved' && $issue->resolution_cutoff_at && $item->occurred_at > $issue->resolution_cutoff_at) {
                    $issue->status = 'unresolved';
                    $issue->resolved_at = null;
                    $issue->state_version++;
                    $regression = true;
                }
                if ($first) {
                    $issue->recovered_only = in_array($item->mode, ['reconciliation', 'historical_import'], true);
                } elseif ($item->mode === 'live') {
                    $issue->recovered_only = false;
                }
                if (($first || $regression) && $item->mode === 'live') {
                    $issue->alert_generation++;
                }
                $this->issues->save($issue);
                $expires = $item->occurred_at->addDays($policy['retention']['occurrence_days']);
                if ($expires->isFuture() && $item->occurred_at > CarbonImmutable::parse(app(RetentionPolicy::class)->floors()['evidence'], 'UTC')) {
                    $json = json_encode($payload, JSON_THROW_ON_ERROR);
                    $record = $this->evidence->record([
                        'occurrence_id' => $occurrence->id,
                        'installation_id' => $i->id,
                        'privacy_revision' => $policy['revision'],
                        'payload_ciphertext' => Crypt::encryptString($json),
                        'plaintext_bytes' => strlen($json),
                        'payload_digest' => hash('sha256', $json),
                        'route_name' => $payload['execution']['route_name'] ?? null,
                        'path_template' => $payload['execution']['path_template'] ?? null,
                        'job_class' => $payload['execution']['job_class'] ?? null,
                        'command_name' => $payload['execution']['command'] ?? null,
                        'release' => $payload['source']['release'] ?? null,
                        'impact_subject_hash' => $payload['impact']['id'] ?? null,
                        'impact_key_id' => $payload['impact']['key_id'] ?? null,
                        'coverage' => $payload['coverage'],
                        'expires_at' => $expires,
                    ]);
                    $this->tags->record($record, $payload['tags']);
                }
                $this->metrics->record($issue, $item->occurred_at, $first, $regression, $first ? null : $oldFirst, $policy['retention']['aggregate_days']);
                if ($first || $regression) {
                    $type = $first ? 'error.created' : 'error.regressed';
                    $this->activity->record($issue, $type, $occurrence->public_id, $item->occurred_at, $item->mode);
                    if (! $test && ! $historical) {
                        $this->events->record($issue, $occurrence, $type, [
                            'class' => $e['class'],
                            'message' => $e['message'],
                            'file' => $e['file'],
                            'line' => $e['line'],
                        ], $i->generation);
                    }
                }
            }
            if ($occurrence->wasRecentlyCreated && ! $historical) {
                app(AlertPlanning::class)->occurrence($issue, $occurrence);
            }
            if ($payload['marker']['probe_id'] ?? null) {
                $this->receipts->transition($payload['marker']['probe_id'], ['pending', 'captured'], [
                    'state' => 'completed',
                    'result_type' => 'occurrence',
                    'result_public_id' => $occurrence->public_id,
                    'completed_at' => CarbonImmutable::now('UTC'),
                ]);
            }
            $this->complete($item);

            return true;
        }, 5);
    }

    private function expand(CaptureInbox $claimed, array $payload): bool
    {
        $rows = app(StoredEntries::class)->exceptions((int) $claimed->source_sequence, 100, $claimed->batch_uuid);
        if (! $rows && $claimed->source_sequence === 0) {
            throw new CaptureFailure('source_batch_unavailable');
        }
        $installation = $this->installations->current();
        foreach ($rows as $row) {
            if (app(SourceMarker::class)->read($installation, $row['uuid'], $row['tags'])) {
                $payload['scanned_count']++;
            }
        }
        app(StoredExceptionAdapter::class)->replayRows($rows, $claimed->mode);
        $more = count($rows) === 100 || ($rows && end($rows)['page_has_more']);
        if (! $more && $payload['scanned_count'] < $payload['expected_count']) {
            throw new CaptureFailure('source_batch_incomplete');
        }

        return Database::connection()->transaction(function () use ($claimed, $rows, $payload, $more) {
            $i = $this->installations->fence();
            $item = $this->inbox->lock($claimed->id);
            if (! $item || $item->claim_token !== $claimed->claim_token) {
                return false;
            }
            if ($i->generation !== $item->generation || $i->source_epoch !== $item->source_epoch) {
                $item->state = 'cancelled';
                $item->completed_at = CarbonImmutable::now('UTC');
                $this->inbox->save($item);

                return false;
            }
            if (! $more) {
                $this->complete($item);
            } else {
                $json = json_encode($payload, JSON_THROW_ON_ERROR);
                $item->payload_ciphertext = Crypt::encryptString($json);
                $item->payload_digest = hash('sha256', $json);
                $item->plaintext_bytes = strlen($json);
                $item->source_sequence = (int) end($rows)['sequence'];
                $item->state = 'pending';
                $item->attempt_count = 0;
                $item->claim_token = null;
                $item->lease_until = null;
                $item->eligible_at = CarbonImmutable::now('UTC');
                $this->inbox->save($item);
            }

            return true;
        }, 5);
    }

    private function complete(CaptureInbox $item): void
    {
        $item->state = 'completed';
        $item->processed_at = CarbonImmutable::now('UTC');
        $item->completed_at = CarbonImmutable::now('UTC');
        $item->claim_token = null;
        $item->lease_until = null;
        $item->last_error_code = null;
        $this->inbox->save($item);
    }

    private function failed(int $id, ?string $token, string $code, bool $retry): void
    {
        Database::connection()->transaction(function () use ($id, $token, $code, $retry) {
            $this->installations->fence();
            $item = $this->inbox->lock($id);
            if (! $item || $item->claim_token !== $token) {
                return;
            }
            $item->state = $retry && $item->attempt_count < 5 ? 'retrying' : 'failed';
            $item->last_error_code = $code;
            $item->claim_token = null;
            $item->lease_until = null;
            $item->eligible_at = CarbonImmutable::now('UTC')->addSeconds([60, 300, 900, 3600][min(3, $item->attempt_count - 1)]);
            if ($item->state === 'failed') {
                $item->completed_at = CarbonImmutable::now('UTC');
            }
            $this->inbox->save($item);
        }, 5);
        $this->observation->record('capture_worker', 'failed', 'Capture processing failed; inspect the inbox state and safe error code.', ['code' => $code]);
    }
}
