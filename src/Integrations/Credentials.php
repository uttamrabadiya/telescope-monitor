<?php

namespace TelescopeMonitor\Integrations;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use TelescopeMonitor\Collaboration\Identity;
use TelescopeMonitor\Models\Connection;
use TelescopeMonitor\Models\Secret;

final class Credentials
{
    public function __construct(private Identity $identity, private DestinationPolicy $policy) {}

    public function value(Secret $secret): string
    {
        if ($secret->source === 'deployment') {
            $registry = config('exception-monitor.integrations.credentials', []);
            $record = $registry[$secret->deployment_reference] ?? null;
            if (! is_array($record) || ($record['purpose'] ?? null) !== $secret->secret_name || ! is_string($record['value'] ?? null) || $record['value'] === '') {
                throw new \RuntimeException('credential_unavailable');
            }

            return $record['value'];
        }

        return Crypt::decryptString($secret->ciphertext);
    }

    public function store(Connection $c, string $purpose, ?string $value, string $actor, string $state = 'active', ?string $reference = null): Secret
    {
        $revision = 1 + (int) Secret::query()->where('connection_id', $c->id)->where('secret_name', $purpose)->max('revision');

        return Secret::query()->create([
            'public_id' => (string) Str::uuid(),
            'installation_id' => $c->installation_id,
            'connection_id' => $c->id,
            'owner_key' => 'connection:'.$c->public_id,
            'secret_name' => $purpose,
            'revision' => $revision,
            'key_id' => (string) Str::uuid(),
            'source' => $reference ? 'deployment' : 'database',
            'deployment_reference' => $reference,
            'ciphertext' => $reference ? null : Crypt::encryptString($value ?? ''),
            'state' => $state,
            'activated_at' => $state === 'active' ? now('UTC') : null,
            'created_by_actor_key' => $actor,
        ]);
    }

    public function active(Connection $c, string $purpose, ?string $keyId = null): Secret
    {
        $q = Secret::query()->where('installation_id', $c->installation_id)->where('connection_id', $c->id)->where('secret_name', $purpose);
        if ($keyId !== null) {
            $q->where('key_id', $keyId)->whereIn('state', ['active', 'staged']);
        } else {
            $q->where('state', 'active');
        }

        return $q->firstOrFail();
    }

    public function target(Connection $c, ?string $testKey = null): array
    {
        if ($c->provider === 'email') {
            $secret = $this->active($c, 'email_address');
            $address = $this->value($secret);
            $fp = $this->identity->digest(['provider' => 'email', 'email' => strtolower(trim($address))]);
            if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException;
            }
            $target = ['provider' => 'email', 'email' => $address, 'key_id' => $secret->key_id];
        } elseif ($c->provider === 'github') {
            $repo = $this->policy->repository($c->target_public['owner'], $c->target_public['repo']);
            $fp = $this->identity->digest(['provider' => 'github'] + $repo);
            $secret = $this->active($c, 'github_token');
            $token = $this->value($secret);
            if (! $token || preg_match('/[\x00-\x20\x7f]/', $token)) {
                throw new \RuntimeException;
            }
            $target = ['provider' => 'github'] + $repo + ['token' => $token, 'key_id' => $secret->key_id];
        } else {
            $secret = $this->active($c, 'endpoint');
            $url = $this->value($secret);
            $url = $this->policy->url($url, $c->provider)['url'];
            $fp = $this->identity->digest(['provider' => $c->provider, 'url' => $url]);
            $target = ['provider' => $c->provider, 'url' => $url, 'key_id' => $secret->key_id];
            if ($c->provider === 'webhook') {
                $sign = $this->active($c, 'webhook_signing', $testKey);
                $target += ['signing_secret' => $this->value($sign), 'signing_key_id' => $sign->key_id];
            }
        }
        if (! hash_equals($c->target_fingerprint, $fp)) {
            throw new \RuntimeException('target_changed');
        }

        return $target;
    }

    public function cleanup(): int
    {
        $ids = Secret::query()->whereNotNull('connection_id')->where('state', 'retiring')->whereNotNull('retire_after')->where('retire_after', '<=', now('UTC')->format('Y-m-d H:i:s.u'))->orderBy('id')->limit(1000)->pluck('id');

        return Secret::query()->whereKey($ids)->update(['ciphertext' => null, 'state' => 'retired']);
    }
}
