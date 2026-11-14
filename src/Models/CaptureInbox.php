<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property int $generation
 * @property string $source_epoch
 * @property string $capture_key
 * @property string $kind
 * @property string $mode
 * @property string|null $source_uuid
 * @property string|null $batch_uuid
 * @property int|null $source_sequence
 * @property string $environment
 * @property CarbonImmutable $occurred_at
 * @property string $time_precision
 * @property CarbonImmutable $received_at
 * @property int $grouping_version
 * @property string|null $fingerprint_hash
 * @property int $privacy_revision
 * @property int $envelope_version
 * @property string|null $payload_ciphertext
 * @property int $plaintext_bytes
 * @property string|null $payload_digest
 * @property string $state
 * @property int $attempt_count
 * @property CarbonImmutable $eligible_at
 * @property string|null $claim_token
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $last_error_code
 */
final class CaptureInbox extends Model
{
    use UsesUtcTimestamps;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'telescope_monitor_capture_inbox';

    protected $guarded = [];

    protected $hidden = ['payload_ciphertext'];

    protected $casts = [
        'installation_id' => 'integer',
        'generation' => 'integer',
        'source_sequence' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'grouping_version' => 'integer',
        'privacy_revision' => 'integer',
        'envelope_version' => 'integer',
        'plaintext_bytes' => 'integer',
        'attempt_count' => 'integer',
        'eligible_at' => 'immutable_datetime',
        'lease_until' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];
}
