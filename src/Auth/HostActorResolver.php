<?php

namespace TelescopeMonitor\Auth;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use TelescopeMonitor\Data\Actor;

final class HostActorResolver implements ActorResolver
{
    public function current(): ?Actor
    {
        $user = Auth::guard(config('telescope-monitor.guard'))->user();
        if (! $user) {
            return app(TelescopeAccess::class)->sharedAvailable()
                ? new Actor(TelescopeAccess::SHARED_KEY, TelescopeAccess::SHARED_LABEL)
                : null;
        }

        return new Actor($this->provider().':'.(string) $user->getAuthIdentifier(), (string) ($user->name ?? $user->fullName ?? 'Authenticated user'));
    }

    public function resolve(Actor $actor): ?Authenticatable
    {
        if ($actor->key === TelescopeAccess::SHARED_KEY) {
            return app(TelescopeAccess::class)->sharedAvailable()
                ? new GenericUser(['id' => TelescopeAccess::SHARED_KEY, 'name' => TelescopeAccess::SHARED_LABEL])
                : null;
        }
        $prefix = $this->provider().':';
        if (! str_starts_with($actor->key, $prefix)) {
            return null;
        }

        return Auth::createUserProvider($this->provider())?->retrieveById(substr($actor->key, strlen($prefix)));
    }

    private function provider(): string
    {
        $guard = config('telescope-monitor.guard') ?? config('auth.defaults.guard');

        return (string) config('auth.guards.'.$guard.'.provider', 'users');
    }
}
