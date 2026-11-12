<?php

namespace TelescopeMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;

final class MaintenanceGuard
{
    public function __construct(private InstallationRepository $installations) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installations->storageState() !== 'ready') {
            return $next($request);
        }
        $i = $this->installations->current();
        if ($i->state === 'active') {
            return $next($request);
        }
        $path = trim($request->path(), '/');
        $base = trim(config('exception-monitor.path'), '/').'/api/v1/';
        $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : '';
        $readable = in_array($relative, ['bootstrap', 'operations', 'health', 'setup/status'], true) || str_starts_with($relative, 'settings/');
        $preview = in_array($relative, ['health/check', 'retention/preview', 'privacy/preview'], true);
        if (($request->isMethod('GET') && $readable) || ($request->isMethod('POST') && $preview)) {
            return $next($request);
        }

        return response()->json([
            'error' => [
                'code' => 'maintenance_in_progress',
                'message' => 'The installation is '.$i->state.'. Monitoring data and mutations are temporarily unavailable. Inspect Operations and resume the deployment maintenance command.',
            ],
        ], 409)->header('Cache-Control', 'private, no-store');
    }
}
