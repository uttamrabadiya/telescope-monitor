<?php

namespace TelescopeMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use TelescopeMonitor\Foundation\DashboardAssets;
use TelescopeMonitor\Foundation\PackagePath;

final class DashboardController
{
    public function __invoke(Request $request, DashboardAssets $assets, PackagePath $path): View
    {
        return view('exception-monitor::shell', [
            'entry' => $assets->entry(),
            'build' => $assets->build(),
            'base' => $path->base(),
            'nonce' => $request->attributes->get('csp_nonce'),
        ]);
    }
}
