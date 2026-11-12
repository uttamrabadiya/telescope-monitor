<?php

namespace TelescopeMonitor\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Capture\CaptureObservation;
use TelescopeMonitor\Models\Installation;
use TelescopeMonitor\Models\PurgeRun;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Storage\Database;
use TelescopeMonitor\Telescope\StoredEntries;

final class PurgeOperations
{
    public const TABLES = [
        'notification_gates',
        'delivery_attempts',
        'delivery_rules',
        'linked_issues',
        'deliveries',
        'alert_events',
        'notes',
        'activity',
        'metric_buckets',
        'evidence_tags',
        'occurrence_evidence',
        'occurrences',
        'capture_inbox',
        'issues',
    ];

    public function __construct(private InstallationRepository $installations, private StoredEntries $source) {}

    public function ready(): bool
    {
        return Database::schema()->hasTable('exception_monitor_purge_runs');
    }

    private function confirmed(string $installation, string $confirm): Installation
    {
        $i = $this->installations->current();
        if (! $this->ready() || ! hash_equals($i->public_id, $installation) || ! hash_equals($i->public_id, $confirm)) {
            throw new MaintenanceFailure('installation_confirmation_required');
        }

        return $i;
    }

    public function status(?string $runId = null): array
    {
        $i = $this->installations->current();
        $run = $this->ready() ? PurgeRun::query()->where('installation_id', $i->id)->when($runId, fn ($q) => $q->where('public_id', $runId))->orderByDesc('id')->first() : null;
        if ($runId && ! $run) {
            throw new MaintenanceFailure('purge_run_not_found');
        }

        return [
            'installation' => $i->public_id,
            'installation_state' => $i->state,
            'generation' => $i->generation,
            'run' => $run ? [
                'id' => $run->public_id,
                'state' => $run->state,
                'step' => $run->current_step,
                'from_generation' => $run->from_generation,
                'to_generation' => $run->to_generation,
                'counts' => $run->deletion_counts,
                'started_at' => $run->started_at->toISOString(),
                'completed_at' => $run->completed_at?->toISOString(),
                'error' => $run->safe_error_code,
                'source_floor' => $run->source_sequence_floor,
                'cutoff' => $run->source_time_cutoff?->toISOString(),
            ] : null,
        ];
    }

    private function rows(string $suffix, int $installation)
    {
        $q = Database::connection()->table('exception_monitor_'.$suffix);

        return $suffix === 'delivery_rules' ? $q->whereIn('delivery_id', Database::connection()->table('exception_monitor_deliveries')->where('installation_id', $installation)->select('id')) : $q->where('installation_id', $installation);
    }

    public function preview(string $installation): array
    {
        $i = $this->installations->current();
        if ($i->public_id !== $installation || ! $this->ready()) {
            throw new MaintenanceFailure('installation_not_found');
        }$counts = [];
        foreach (self::TABLES as $table) {
            $n = $this->rows($table, $i->id)->limit(10001)->get([Database::connection()->raw('1 AS bounded_row')])->count();
            $counts[$table] = ['count' => min(10000, $n), 'at_least' => $n > 10000];
        }

        return $this->status() + [
            'deletes' => $counts,
            'preserves' => [
                'installation identity and cutoffs',
                'settings',
                'connections and credentials',
                'alert rules',
                'saved views and preferences',
                'minimal command receipts',
                'minimal purge ledger',
            ],
            'external_data' => 'Telescope source, GitHub issues and accepted external messages are not deleted.',
            'command' => 'exception-monitor:purge '.$i->public_id.' --confirm='.$i->public_id.' --limit=500 --batches=1',
        ];
    }

    public function begin(string $installation, string $confirm, string $label = 'deployment'): array
    {
        $this->confirmed($installation, $confirm);
        if ($label === '' || mb_strlen($label) > 191) {
            throw new MaintenanceFailure('invalid_deployment_label');
        }
        $id = Telescope::withoutRecording(fn () => Database::connection()->transaction(function () use ($label) {
            $i = $this->installations->lock();
            if ($i->state !== 'active') {
                $existing = PurgeRun::query()->where('installation_id', $i->id)->whereIn('state', ['draining', 'purging'])->first();
                if ($existing) {
                    return $existing->public_id;
                }throw new MaintenanceFailure('maintenance_in_progress');
            }
            $next = max($i->generation, (int) PurgeRun::query()->where('installation_id', $i->id)->max('to_generation')) + 1;
            $run = PurgeRun::query()->create([
                'public_id' => (string) Str::uuid(),
                'installation_id' => $i->id,
                'from_generation' => $i->generation,
                'to_generation' => $next,
                'source_epoch' => $i->source_epoch,
                'initiated_by_actor_key' => $label,
                'confirmation_digest' => hash('sha256', 'whole-installation:'.$i->public_id.':'.$next),
                'state' => 'draining',
                'current_step' => 'drain',
                'deletion_counts' => [],
                'started_at' => CarbonImmutable::now('UTC'),
            ]);
            $i->state = 'draining';
            $i->last_purge_run_id = $run->id;
            $i->save();

            return $run->public_id;
        }, 5));

        return $this->status($id);
    }

    public function liveLeases(int $installation): int
    {
        $time = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        return Database::connection()->table('exception_monitor_capture_inbox')->where('installation_id', $installation)->where('state', 'processing')->where('lease_until', '>', $time)->count() + Database::connection()->table('exception_monitor_deliveries')->where('installation_id', $installation)->where('status', 'processing')->where('lease_until', '>', $time)->count();
    }

    public function abort(string $installation, string $confirm, string $runId): array
    {
        $this->confirmed($installation, $confirm);
        Database::connection()->transaction(function () use ($runId) {
            $i = $this->installations->lock();
            $run = PurgeRun::query()->where('installation_id', $i->id)->where('public_id', $runId)->lockForUpdate()->firstOrFail();
            if ($run->state !== 'draining' || $i->generation !== $run->from_generation) {
                throw new MaintenanceFailure('purge_already_fenced');
            }$run->state = 'aborted';
            $run->completed_at = CarbonImmutable::now('UTC');
            $run->save();
            $i->state = 'active';
            $i->save();
        }, 5);

        return $this->status($runId);
    }

    public function resume(string $installation, string $confirm, string $runId, int $limit = 500, int $batches = 1): array
    {
        $this->confirmed($installation, $confirm);
        if ($limit < 1 || $limit > 10000 || $batches < 1 || $batches > 100) {
            throw new MaintenanceFailure('invalid_batch_limit');
        }

        return Telescope::withoutRecording(function () use ($runId, $limit, $batches) {
            for ($batch = 0; $batch < $batches; $batch++) {
                $snapshot = $this->status($runId);
                if (in_array($snapshot['run']['state'], ['completed', 'aborted'], true)) {
                    break;
                }
                $source = null;
                try {
                    if ($snapshot['run']['state'] === 'draining') {
                        $this->source->verifySource();
                        $source = [
                            'maximum' => $this->source->maximum(),
                            'signature' => $this->source->signature(),
                            'cutoff' => CarbonImmutable::now('UTC'),
                        ];
                    }
                    $progress = Database::connection()->transaction(function () use ($runId, $limit, $source) {
                        $i = $this->installations->lock();
                        $run = PurgeRun::query()->where('installation_id', $i->id)->where('public_id', $runId)->lockForUpdate()->firstOrFail();
                        if (in_array($run->state, ['completed', 'aborted'], true)) {
                            return true;
                        }
                        if ($run->state === 'draining') {
                            if ($i->state !== 'draining' || $i->generation !== $run->from_generation || $i->source_epoch !== $run->source_epoch) {
                                throw new MaintenanceFailure('purge_fence_changed');
                            }
                            if ($this->liveLeases($i->id) > 0) {
                                return false;
                            }
                            if (! $source || ! hash_equals($i->source_signature ?? '', $source['signature']) || $source['maximum'] < (int) $i->source_sequence_checkpoint) {
                                throw new MaintenanceFailure('source_epoch_review_required');
                            }
                            $cutoff = $source['cutoff'];
                            if ($i->purged_through_at) {
                                $cutoff = $cutoff->max($i->purged_through_at);
                            }
                            $i->generation = $run->to_generation;
                            $i->state = 'purging';
                            $i->source_sequence_floor = max((int) $i->source_sequence_floor, $source['maximum']);
                            $i->source_sequence_checkpoint = max((int) $i->source_sequence_checkpoint, $i->source_sequence_floor);
                            $i->purged_through_at = $cutoff;
                            $setup = $i->setup_state ?? [];
                            unset($setup['completed_at'],$setup['completed_generation'],$setup['completed_epoch'],$setup['acknowledged'],$setup['notification_choice']);
                            $i->setup_state = $setup;
                            $i->config_revision++;
                            $i->save();
                            $run->state = 'purging';
                            $run->current_step = self::TABLES[0];
                            $run->source_sequence_floor = $i->source_sequence_floor;
                            $run->source_time_cutoff = $cutoff;
                            $run->safe_error_code = null;
                            $run->save();
                            Database::connection()->table('exception_monitor_action_receipts')->where('installation_id', $i->id)->whereIn('state', ['pending', 'captured'])->update(['state' => 'cancelled', 'completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);
                            Database::connection()->table('exception_monitor_connections')->where('installation_id', $i->id)->update([
                                'transport_claim_token' => null,
                                'transport_lease_until' => null,
                                'next_send_at' => null,
                                'last_test_at' => null,
                                'last_test_outcome' => null,
                                'options->last_accepted_at' => null,
                            ]);
                            Database::connection()->table('exception_monitor_health_checks')->where('installation_id', $i->id)->whereIn('check_key', ['capture_received', 'capture_worker', 'delivery_worker', 'capture_overlap'])->update([
                                'state' => 'unverified',
                                'last_success_at' => null,
                                'observed_at' => null,
                                'sanitized_details' => json_encode(['detail' => 'Verification was reset by purge.', 'metadata' => []], JSON_THROW_ON_ERROR),
                            ]);

                            return true;
                        }
                        if ($run->state !== 'purging' || $i->state !== 'purging' || $i->generation !== $run->to_generation) {
                            throw new MaintenanceFailure('purge_fence_changed');
                        }
                        $step = array_search($run->current_step, self::TABLES, true);
                        if ($step === false) {
                            foreach (self::TABLES as $table) {
                                if ($this->rows($table, $i->id)->exists()) {
                                    throw new MaintenanceFailure('purge_verification_failed');
                                }
                            }$run->state = 'completed';
                            $run->current_step = 'verified';
                            $run->completed_at = CarbonImmutable::now('UTC');
                            $run->safe_error_code = null;
                            $run->save();
                            $i->state = 'active';
                            $i->save();

                            return true;
                        }
                        $suffix = self::TABLES[$step];
                        $removed = 0;
                        if ($suffix === 'delivery_rules') {
                            $records = $this->rows($suffix, $i->id)->orderBy('delivery_id')->limit($limit)->get(['delivery_id', 'rule_public_id', 'rule_version', 'matching_event_id']);
                            $examined = count($records);
                            foreach ($records->chunk(100) as $chunk) {
                                $removed += $this->rows($suffix, $i->id)->where(function ($q) use ($chunk) {
                                    foreach ($chunk as $row) {
                                        $q->orWhere(fn ($q) => $q->where('delivery_id', $row->delivery_id)->where('rule_public_id', $row->rule_public_id)->where('rule_version', $row->rule_version)->where('matching_event_id', $row->matching_event_id));
                                    }
                                })->delete();
                            }
                        } else {
                            $ids = $this->rows($suffix, $i->id)->orderBy('id')->limit($limit)->pluck('id');
                            $examined = count($ids);
                            $removed = $this->rows($suffix, $i->id)->whereIn('id', $ids)->delete();
                        }$counts = $run->deletion_counts;
                        $counts[self::TABLES[$step]] = ($counts[self::TABLES[$step]] ?? 0) + $removed;
                        $run->deletion_counts = $counts;
                        if ($examined < $limit) {
                            $run->current_step = self::TABLES[$step + 1] ?? 'verify';
                        }$run->safe_error_code = null;
                        $run->save();

                        return true;
                    }, 5);
                    if (! $progress) {
                        break;
                    }
                } catch (\Throwable $e) {
                    try {
                        PurgeRun::query()->where('public_id', $runId)->update(['safe_error_code' => $e instanceof MaintenanceFailure ? $e->getMessage() : 'purge_step_failed']);
                    } catch (\Throwable) {
                    }throw new MaintenanceFailure($e instanceof MaintenanceFailure ? $e->getMessage() : 'purge_step_failed');
                }
            }

            return $this->status($runId);
        });
    }

    public function baseline(string $installation, string $confirm): array
    {
        $this->confirmed($installation, $confirm);
        $signature = $this->source->signature();
        $maximum = $this->source->maximum();
        Database::connection()->transaction(function () use ($signature, $maximum) {
            $i = $this->installations->lock();
            if ($i->state !== 'active') {
                throw new MaintenanceFailure('maintenance_in_progress');
            }$old = $i->source_epoch;
            $i->source_epoch = (string) Str::uuid();
            $i->source_signature = $signature;
            $i->source_sequence_floor = $maximum;
            $i->source_sequence_checkpoint = $maximum;
            $i->purged_through_at = $i->purged_through_at?->max(CarbonImmutable::now('UTC')) ?? CarbonImmutable::now('UTC');
            $setup = $i->setup_state ?? [];
            unset($setup['completed_at'],$setup['completed_generation'],$setup['completed_epoch']);
            $i->setup_state = $setup;
            $i->config_revision++;
            $i->save();
            Database::connection()->table('exception_monitor_action_receipts')->where('installation_id', $i->id)->where('operation', 'capture_probe')->whereIn('state', ['pending', 'captured'])->update(['state' => 'cancelled', 'completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);
            app(CaptureObservation::class)->record('source_baseline', 'passed', 'The source epoch was explicitly rebaselined. Existing source rows were excluded; prior time protection remains.', ['old_epoch' => $old, 'epoch' => $i->source_epoch, 'floor' => $maximum, 'generation' => $i->generation]);
        }, 5);

        return $this->status() + [
            'source_epoch' => $this->installations->current()->source_epoch,
            'coverage' => 'Existing source entries are excluded. New accepted entries can be captured; no historical alerts are created.',
        ];
    }
}
