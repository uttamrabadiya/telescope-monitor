<?php

namespace TelescopeMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use Throwable;

final class RequireStorage
{
    public function __construct(private InstallationRepository $installations) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $state = $this->installations->storageState();
        } catch (Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'storage_unavailable',
                    'message' => 'Monitoring storage could not be read. Review the host database configuration.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
        if ($state !== 'ready') {
            return response()->json([
                'error' => [
                    'code' => $state,
                    'message' => 'Monitoring storage is not ready. Review the installation checks before saving preferences.',
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        }

        return $next($request);
    }
}
