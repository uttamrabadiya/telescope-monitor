<?php

namespace TelescopeMonitor\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

final class TelescopeAccess
{
    public const SHARED_KEY = 'telescope-monitor:shared';

    public const SHARED_LABEL = 'Telescope operator (shared)';

    public const REQUEST_APPROVED = 'telescope-monitor.telescope-approved';

    public function enabled(): bool
    {
        return config('telescope-monitor.authorization.driver', 'telescope') === 'telescope';
    }

    public function sharedAvailable(): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $request = app('request');
        if ($request->attributes->get(self::REQUEST_APPROVED) === true) {
            return true;
        }
        return app(QueuedTelescopeAuthorization::class)->allows(null);
    }

    public function allows(Authenticatable $user, bool $shared, bool $currentActor): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        if ($shared) {
            return $currentActor && $this->sharedAvailable()
                ? true
                : app(QueuedTelescopeAuthorization::class)->allows(null);
        }
        $request = app('request');
        if ($currentActor && $request->attributes->get(self::REQUEST_APPROVED) === true) {
            return true;
        }
        return app(QueuedTelescopeAuthorization::class)->allows($user);
    }
}
