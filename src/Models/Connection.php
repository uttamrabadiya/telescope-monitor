<?php

namespace TelescopeMonitor\Models;

use Carbon\CarbonImmutable;
use TelescopeMonitor\Models\Concerns\UsesUtcTimestamps;

/**
 * @property CarbonImmutable|null $transport_lease_until
 * @property CarbonImmutable|null $next_send_at
 * @property string|null $transport_claim_token
 * @property int $id
 * @property string $public_id
 * @property int $installation_id
 * @property string $provider
 * @property string $display_name
 * @property string $target_fingerprint
 * @property array $target_public
 * @property array $options
 * @property bool $enabled
 * @property int $row_version
 * @property CarbonImmutable|null $disconnected_at
 * @property CarbonImmutable|null $last_test_at
 * @property string|null $last_test_outcome
 */
final class Connection extends Model
{
    use UsesUtcTimestamps;

    protected $table = 'exception_monitor_connections';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['payload_ciphertext'];

    protected $attributes = ['row_version' => 1, 'options_version' => 1];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'target_public' => 'array',
            'options' => 'array',
            'enabled' => 'boolean',
            'row_version' => 'integer',
            'options_version' => 'integer',
            'disconnected_at' => 'immutable_datetime',
            'transport_lease_until' => 'immutable_datetime',
            'next_send_at' => 'immutable_datetime',
            'last_test_at' => 'immutable_datetime',
        ];
    }
}
