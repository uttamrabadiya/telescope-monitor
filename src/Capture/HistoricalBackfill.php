<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Models\Installation;
use TelescopeMonitor\Models\Occurrence;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Telescope\StoredEntries;

final class HistoricalBackfill
{
    public function __construct(private StoredEntries $source, private CaptureInboxRepository $inbox, private CaptureBuffer $buffer, private CaptureProcessor $processor, private CapturePolicy $policy, private Fingerprint $fingerprint, private Sanitizer $sanitizer) {}

    public function run(string $environment, int $after = 0, int $limit = 100, ?int $through = null, bool $dryRun = false): array
    {
        if ($environment === '' || trim($environment) !== $environment || strlen($environment) > 64 || ! mb_check_encoding($environment, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $environment) || $after < 0 || $limit < 1 || $limit > 1000 || ($through !== null && $through < $after)) {
            throw new CaptureFailure('invalid_backfill_options');
        }

        $timezone = config('exception-monitor.capture.source_timezone') ?? config('app.timezone', 'UTC');
        try {
            if (! is_string($timezone)) {
                throw new \InvalidArgumentException;
            }
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new CaptureFailure('invalid_source_timezone');
        }

        return Telescope::withoutRecording(function () use ($environment, $after, $limit, $through, $dryRun, $timezone) {
            if (! config('exception-monitor.enabled') || ! config('exception-monitor.capture.enabled', true) || ! $this->inbox->ready()) {
                throw new CaptureFailure('backfill_unavailable');
            }
            $i = Installation::query()->where('singleton_key', 'default')->first();
            if (! $i || $i->state !== 'active') {
                throw new CaptureFailure('backfill_requires_active_installation');
            }
            $maximum = $this->source->maximum();
            if (($i->source_signature !== null && ! hash_equals($i->source_signature, $this->source->signature())) || $maximum < $i->source_sequence_checkpoint) {
                throw new CaptureFailure('source_epoch_review_required');
            }
            if (! $dryRun) {
                $this->source->verifySource();
            }
            $through = min($through ?? $maximum, $maximum);
            $result = ['dry_run' => $dryRun, 'environment' => $environment, 'through' => $through, 'next_after' => $after, 'scanned' => 0, 'eligible' => 0, 'imported' => 0, 'duplicates' => 0, 'skipped_marked' => 0, 'skipped_invalid' => 0, 'skipped_fenced' => 0, 'pending' => 0, 'failed' => 0];
            $policy = $this->policy->read();
            foreach ($this->source->historicalPage($after, $limit, $through) as $row) {
                $result['scanned']++;
                $result['next_after'] = $row['sequence'];
                if (array_filter($row['tags'], fn ($tag) => str_starts_with($tag, 'em2c|') || str_starts_with($tag, 'em2g|'))) {
                    $result['skipped_marked']++;

                    continue;
                }
                $content = $this->content($row);
                $time = $this->time($row['created_at'], $timezone);
                if ($content === null || $time === null || ! Str::isUuid($row['uuid']) || ($row['batch_uuid'] !== null && ! Str::isUuid($row['batch_uuid']))) {
                    $result['skipped_invalid']++;

                    continue;
                }
                if (($i->purged_through_at && $time <= $i->purged_through_at) || $row['sequence'] <= $i->source_sequence_floor) {
                    $result['skipped_fenced']++;

                    continue;
                }
                if (Occurrence::query()->where('installation_id', $i->id)->where('source_epoch', $i->source_epoch)->where('source_uuid', $row['uuid'])->exists()) {
                    $result['duplicates']++;

                    continue;
                }
                $result['eligible']++;
                if ($dryRun) {
                    continue;
                }
                $marker = ['generation' => $i->generation, 'source_epoch' => $i->source_epoch, 'environment' => $environment, 'occurred_at' => $time->format('Y-m-d H:i:s.u'), 'time_precision' => str_contains($row['created_at'], '.') ? 'microsecond' : 'second', 'grouping_version' => (int) config('exception-monitor.capture.grouping_version', 1), 'fingerprint_hash' => $this->fingerprint->hash($environment, $content), 'probe_id' => null, 'policy' => $policy];
                $context = $this->sanitizer->batch($row['batch_uuid'] ? $this->source->context($row['batch_uuid']) : [], $policy);
                $payload = $this->sanitizer->envelope($content, $marker, $context, $row['tags']);
                $payload['coverage']['reportability'] = 'unavailable';
                $item = $this->buffer->enqueue($payload['marker'], $row['uuid'], $row['batch_uuid'], $row['sequence'], $payload, 'historical_import');
                if (! $item) {
                    throw new CaptureFailure('installation_fenced');
                }
                // A live producer may have won the same UUID reservation. Never process its work here.
                if ($item->mode !== 'historical_import') {
                    $result['duplicates']++;
                } elseif ($this->processor->process($item->id, $i->generation)) {
                    $result['imported']++;
                } else {
                    $state = $item->fresh()?->state;
                    $counter = match ($state) {
                        'completed' => 'duplicates', 'failed' => 'failed', 'cancelled' => 'skipped_fenced', default => 'pending'
                    };
                    $result[$counter]++;
                }
            }
            $result['has_more'] = $this->source->hasHistoricalAfter($result['next_after'], $through);

            return $result;
        });
    }

    private function content(array $row): ?array
    {
        $c = $row['content'];
        if (! is_array($c) || ! is_string($c['class'] ?? null) || $c['class'] === '' || strlen($c['class']) > 512 || ! is_string($c['message'] ?? null) || ! is_string($c['file'] ?? null) || strlen($c['file']) > 4096 || ! is_int($c['line'] ?? null) || $c['line'] < 0) {
            return null;
        }

        if (is_array($c['trace'] ?? null)) {
            foreach (array_slice($c['trace'], 0, 100) as $frame) {
                if (is_array($frame) && ((isset($frame['file']) && ! is_string($frame['file'])) || (isset($frame['line']) && (! is_int($frame['line']) || $frame['line'] < 0)))) {
                    return null;
                }
            }
        }

        // Never adopt unsigned package-private provenance, route or release metadata from legacy JSON.
        return array_intersect_key($c, array_flip(['class', 'message', 'file', 'line', 'trace', 'user']));
    }

    private function time(mixed $raw, string $timezone): ?CarbonImmutable
    {
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $raw)) {
            return null;
        }
        try {
            $time = CarbonImmutable::parse($raw, $timezone);

            return $time->format('Y-m-d H:i:s') === substr($raw, 0, 19) && ! $time->isFuture() ? $time->utc() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
