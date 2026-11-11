<?php

namespace TelescopeMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Auth\TelescopeAccess;

final class Authorize
{
    public function __construct(private Capabilities $capabilities, private ActorResolver $actors) {}

    public function handle(Request $request, Closure $next, string $capability = 'view'): Response
    {
        if (app(TelescopeAccess::class)->enabled()) {
            return app(\Laravel\Telescope\Http\Middleware\Authorize::class)->handle($request, function (Request $request) use ($next, $capability) {
                $previous = $request->attributes->get(TelescopeAccess::REQUEST_APPROVED);
                $request->attributes->set(TelescopeAccess::REQUEST_APPROVED, true);
                try {
                    return $this->authorized($request, $next, $capability);
                } finally {
                    if ($previous === true) {
                        $request->attributes->set(TelescopeAccess::REQUEST_APPROVED, true);
                    } else {
                        $request->attributes->remove(TelescopeAccess::REQUEST_APPROVED);
                    }
                }
            });
        }

        return $this->authorized($request, $next, $capability);
    }

    private function authorized(Request $request, Closure $next, string $capability): Response
    {
        if (! $this->capabilities->allows('view') || ! $this->capabilities->allows($capability)) {
            $status = $this->actors->current() ? 403 : 401;
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $status === 401 ? 'unauthenticated' : 'forbidden',
                        'message' => $status === 401 ? 'Sign in to access Exception Monitor.' : 'You do not have permission for this action.',
                    ],
                ], $status)->header('Cache-Control', 'private, no-store');
            }
            abort($status);
        }

        return $next($request);
    }
}
