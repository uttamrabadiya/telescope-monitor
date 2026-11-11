<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use TelescopeMonitor\Models\ActionReceipt;
use TelescopeMonitor\Repositories\Contracts\ActionReceiptRepository;

final class EloquentActionReceiptRepository implements ActionReceiptRepository
{
    public function createProbe(int $installation, int $generation, string $actor, string $clientKey, string $digest): ActionReceipt
    {
        return ActionReceipt::query()->createOrFirst([
            'installation_id' => $installation,
            'actor_lookup_hash' => hash_hmac('sha256', $actor, $digest),
            'operation' => 'capture_probe',
            'client_key' => $clientKey,
        ], [
            'public_id' => (string) Str::uuid(),
            'generation' => $generation,
            'actor_key' => $actor,
            'keyed_request_digest' => $digest,
            'state' => 'pending',
            'expires_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);
    }

    public function probe(string $id): ?ActionReceipt
    {
        return ActionReceipt::query()->where('operation', 'capture_probe')->where('public_id', $id)->first();
    }

    public function update(string $id, array $data): void
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format('Y-m-d H:i:s.u');
            }
        }
        ActionReceipt::query()->where('operation', 'capture_probe')->where('public_id', $id)->update($data);
    }

    public function transition(string $id, array $states, array $data): void
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format('Y-m-d H:i:s.u');
            }
        }
        ActionReceipt::query()->where('operation', 'capture_probe')->where('public_id', $id)->whereIn('state', $states)->update($data);
    }

    public function pending(int $limit): array
    {
        return ActionReceipt::query()->where('operation', 'capture_probe')->where('state', 'pending')->where('updated_at', '<=', now('UTC')->subMinutes(2)->format('Y-m-d H:i:s.u'))->orderBy('id')->limit($limit)->pluck('public_id')->all();
    }
}
