<?php

namespace TelescopeMonitor\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use TelescopeMonitor\Foundation\DashboardAssets;

final class AssetController
{
    public function __invoke(string $build, string $file, DashboardAssets $assets): BinaryFileResponse
    {
        $path = $assets->resolve($build, $file);
        abort_unless($path !== null, 404);
        $type = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'js' => 'text/javascript',
            'css' => 'text/css',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            default => 'application/octet-stream',
        };

        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
