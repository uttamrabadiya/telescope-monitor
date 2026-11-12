<?php

namespace TelescopeMonitor\Capture;

use Illuminate\Support\Facades\Crypt;
use TelescopeMonitor\Models\Installation;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\SecretRepository;
use TelescopeMonitor\Storage\Database;

final class CaptureKeys
{
    public function __construct(private InstallationRepository $installations, private SecretRepository $secrets) {}

    public function key(string $purpose = 'capture_identity'): array
    {
        return $this->forInstallation($this->installations->current(), $purpose);
    }

    public function forInstallation(Installation $installation, string $purpose = 'capture_identity'): array
    {
        $record = $this->secrets->find($installation->id, $purpose);
        if (! $record) {
            $record = Database::connection()->transaction(function () use ($purpose) {
                $installation = $this->installations->lock();
                $existing = $this->secrets->find($installation->id, $purpose);
                if ($existing) {
                    return $existing;
                }
                $secret = $this->secrets->create($installation->id, $purpose, Crypt::encryptString(base64_encode(random_bytes(32))));
                $this->installations->advanceConfiguration($installation);

                return $secret;
            }, 5);
        }
        $value = base64_decode(Crypt::decryptString($record->ciphertext), true);
        if ($value === false || strlen($value) !== 32) {
            throw new CaptureFailure('identity_key_invalid');
        }

        return ['id' => $record->key_id, 'value' => $value];
    }

    public function byId(int $installation, string $id): ?string
    {
        $secret = $this->secrets->byKey($installation, $id);
        if (! $secret || $secret->secret_name !== 'capture_identity') {
            return null;
        }
        $value = base64_decode(Crypt::decryptString($secret->ciphertext), true);

        return $value !== false && strlen($value) === 32 ? $value : null;
    }
}
