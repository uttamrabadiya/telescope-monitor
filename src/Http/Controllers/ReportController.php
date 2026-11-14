<?php

namespace TelescopeMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Telescope\Telescope;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use TelescopeMonitor\Foundation\ConfigurationConflict;
use TelescopeMonitor\Reports\ReportScheduler;

final class ReportController
{
    public function preview(Request $request, string $kind, ReportScheduler $reports)
    {
        $v = $request->validate(['client_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0']]);
        try {
            return response()->json([
                'telescope_monitor_private' => Telescope::withoutRecording(fn () => $reports->queue($kind, $v['client_key'], (int) $v['version'])),
            ], 202)->header('Cache-Control', 'private, no-store');
        } catch (ConfigurationConflict $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 409)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            return response()->json(['error' => ['message' => 'The report preview could not be prepared. Review report settings and monitoring storage.']], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
