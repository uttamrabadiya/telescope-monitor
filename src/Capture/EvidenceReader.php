<?php

namespace TelescopeMonitor\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\OccurrenceEvidenceRepository;
use TelescopeMonitor\Repositories\Contracts\OccurrenceRepository;

final class EvidenceReader
{
    public function __construct(private InstallationRepository $installations, private OccurrenceRepository $occurrences, private OccurrenceEvidenceRepository $evidence) {}

    public function read(string $publicId, bool $includeReproduce = false): ?array
    {
        $occurrence = $this->occurrences->findByPublicId($publicId, $this->installations->current()->id);
        if (! $occurrence) {
            return null;
        }
        $evidence = $this->evidence->find($occurrence->id);
        $result = [
            'id' => $occurrence->public_id,
            'source_uuid' => $occurrence->source_uuid,
            'telescope_url' => rtrim(config('app.url'), '/').'/'.trim(config('telescope.path', 'telescope'), '/').'/exceptions/'.$occurrence->source_uuid,
            'occurred_at' => $occurrence->occurred_at->format('Y-m-d\TH:i:s.u\Z'),
            'origin' => $occurrence->origin,
            'mode' => $occurrence->mode,
            'evidence_state' => 'expired',
            'evidence' => null,
        ];
        if (! $evidence || $evidence->expires_at <= CarbonImmutable::now('UTC') || $occurrence->occurred_at <= CarbonImmutable::parse(app(RetentionPolicy::class)->floors()['evidence'], 'UTC')) {
            return $result;
        }
        try {
            $json = Crypt::decryptString($evidence->payload_ciphertext);
            if (! hash_equals($evidence->payload_digest, hash('sha256', $json))) {
                throw new CaptureFailure('integrity_failed');
            }
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $payload = app(Sanitizer::class)->restrict($payload, app(CapturePolicy::class)->read());
            if (isset($payload['reproduce']) && ! $includeReproduce) {
                $payload['reproduce'] = ['version' => 1, 'state' => 'restricted'];
            }
            $snapshot = $payload['source'] ?? null;
            foreach ($payload['exception']['trace'] as &$frame) {
                $frame['source_url'] = app(SourceSnapshot::class)->link($snapshot, $frame['file'] ?? '', (int) ($frame['line'] ?? 0));
            }
            unset($frame);
            $result['source_url'] = app(SourceSnapshot::class)->link($snapshot, $payload['exception']['file'], $payload['exception']['line']);
            $result['evidence_state'] = 'available';
            $result['evidence'] = $payload;
        } catch (\Throwable) {
            $result['evidence_state'] = 'decryption_failed';
        }

        return $result;
    }
}
