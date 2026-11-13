<?php

namespace TelescopeMonitor\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Request as RequestFacade;
use Laravel\Telescope\Telescope;

/** Recheck Telescope as the saved actor, without borrowing another request's credentials. */
final class QueuedTelescopeAuthorization
{
    private ?Request $workerRequest = null;

    /** True only inside this service's isolated CLI policy evaluation, never from client input. */
    public function isWorkerContext(?Request $request = null): bool
    {
        $request ??= app('request');

        return app()->runningInConsole()
            && $request === $this->workerRequest
            && $request === app('request')
            && $request->method() === 'CLI'
            && $request->attributes->get('telescope-monitor.worker') === true;
    }

    public function allows(?Authenticatable $user): bool
    {
        $originalAuth = Auth::getFacadeRoot();
        $originalGate = Gate::getFacadeRoot();
        $originalRequest = app('request');
        $originalUserResolver = $originalRequest->getUserResolver();
        $guard = config('telescope-monitor.guard') ?? config('auth.defaults.guard');
        // No synthetic loopback address, browser headers, cookies or session.
        $request = new Request(server: ['REQUEST_METHOD' => 'CLI']);
        $request->attributes->set('telescope-monitor.worker', true);
        $scopedAuth = new class (app(), $user, $request, $guard) extends AuthManager
        {
            public function __construct($app, private ?Authenticatable $actor, private Request $context, private string $actorGuard)
            {
                parent::__construct($app);
            }

            public function guard($name = null): Guard
            {
                $name ??= $this->actorGuard;

                return $this->guards[$name] ??= new RequestGuard(fn () => $name === $this->actorGuard ? $this->actor : null, $this->context);
            }
        };
        $previousWorkerRequest = $this->workerRequest;
        try {
            $this->workerRequest = $request;
            Auth::swap($scopedAuth);
            RequestFacade::swap($request);
            $request->setUserResolver(fn ($requested = null) => $requested === null || $requested === $guard ? $user : null);
            Gate::swap($originalGate->forUser($user));

            return (bool) Telescope::check($request);
        } catch (\Throwable) {
            return false;
        } finally {
            $this->workerRequest = $previousWorkerRequest;
            Gate::swap($originalGate);
            RequestFacade::swap($originalRequest);
            $originalRequest->setUserResolver($originalUserResolver);
            Auth::swap($originalAuth);
        }
    }
}
