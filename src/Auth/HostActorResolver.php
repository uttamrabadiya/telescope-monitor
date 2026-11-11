<?php

namespace TelescopeMonitor\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use TelescopeMonitor\Data\Actor;

final class HostActorResolver implements ActorResolver
{
    public function current(): ?Actor
    {
        $user = Auth::guard(config('exception-monitor.guard'))->user();
        if (! $user) {
            return null;
        }

        return new Actor($this->provider().':'.(string) $user->getAuthIdentifier(), (string) ($user->name ?? 'Authenticated user'));
    }

    public function resolve(Actor $actor): ?Authenticatable
    {
        $prefix = $this->provider().':';
        if (! str_starts_with($actor->key, $prefix)) {
            return null;
        }

        return Auth::createUserProvider($this->provider())?->retrieveById(substr($actor->key, strlen($prefix)));
    }

    private function provider(): string
    {
        $guard = config('exception-monitor.guard') ?? config('auth.defaults.guard');

        return (string) config('auth.guards.'.$guard.'.provider', 'users');
    }
}
