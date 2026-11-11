<?php

namespace TelescopeMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Auth\Capabilities;

final class Authorize
{
    public function __construct(private Capabilities $capabilities, private ActorResolver $actors) {}

    public function handle(Request $request, Closure $next, string $capability = 'view'): Response
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
