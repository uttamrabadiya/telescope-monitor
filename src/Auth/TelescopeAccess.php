<?php

namespace TelescopeMonitor\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\Telescope;

final class TelescopeAccess
{
    public const SHARED_KEY = 'telescope-monitor:shared';

    public const SHARED_LABEL = 'Telescope operator (shared)';

    public const REQUEST_APPROVED = 'exception-monitor.telescope-approved';

    public function enabled(): bool
    {
        return config('exception-monitor.authorization.driver', 'telescope') === 'telescope';
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
        // Queue workers have no originating HTTP request to replay. Local Telescope
        // access is rechecked; deployed guest jobs require an explicit host grant.
        try {
            return (app()->environment('local') && Telescope::check($request))
                || (app()->runningInConsole() && config('exception-monitor.authorization.allow_guest_jobs', false) === true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function allows(Authenticatable $user, bool $shared, bool $currentActor): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        if ($shared) {
            return $this->sharedAvailable();
        }
        $request = app('request');
        if ($currentActor && $request->attributes->get(self::REQUEST_APPROVED) === true) {
            return true;
        }
        try {
            if (app()->environment('local')) {
                return (bool) Telescope::check($request);
            }

            // Laravel's default Telescope policy outside local is viewTelescope.
            // Re-resolve the saved user and evaluate that gate for queued work.
            return Gate::has('viewTelescope') && Gate::forUser($user)->allows('viewTelescope');
        } catch (\Throwable) {
            return false;
        }
    }
}
