<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use TelescopeMonitor\Models\Secret;
use TelescopeMonitor\Repositories\Contracts\SecretRepository;

final class EloquentSecretRepository implements SecretRepository
{
    public function find(int $installation, string $purpose): ?Secret
    {
        return Secret::query()->where('installation_id', $installation)->where('owner_key', 'installation')->where('secret_name', $purpose)->where('state', 'active')->first();
    }

    public function byKey(int $installation, string $key): ?Secret
    {
        return Secret::query()->where('installation_id', $installation)->where('owner_key', 'installation')->where('key_id', $key)->whereIn('state', ['active', 'retiring'])->first();
    }

    public function create(int $installation, string $purpose, string $ciphertext): Secret
    {
        return Secret::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $installation,
            'owner_key' => 'installation',
            'secret_name' => $purpose,
            'revision' => 1,
            'key_id' => (string) Str::uuid(),
            'ciphertext' => $ciphertext,
            'activated_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
