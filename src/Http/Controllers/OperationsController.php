<?php

namespace TelescopeMonitor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Foundation\ConfigurationConflict;
use TelescopeMonitor\Operations\OperationalStatus;
use TelescopeMonitor\Operations\RetentionSweep;

final class OperationsController
{
    private function run(callable $work): JsonResponse
    {
        try {
            return response()->json(['data' => Telescope::withoutRecording($work)])->header('Cache-Control', 'private, no-store');
        } catch (ConfigurationConflict $e) {
            return response()->json(['error' => ['code' => 'operations_conflict', 'message' => $e->getMessage()]], 409)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            return response()->json(['error' => ['code' => 'operations_unavailable', 'message' => 'Operational data is unavailable. Review migrations, current maintenance state and host storage.']], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function read(OperationalStatus $status): JsonResponse
    {
        return $this->run(fn () => $status->read());
    }

    public function setup(OperationalStatus $status): JsonResponse
    {
        return $this->run(fn () => $status->setup());
    }

    public function finish(OperationalStatus $status): JsonResponse
    {
        return $this->run(fn () => $status->finish());
    }

    public function preview(Request $r, RetentionSweep $sweep): JsonResponse
    {
        $v = $r->validate(['values' => ['required', 'array:occurrence_days,delivery_days,aggregate_days,activity_days'], 'values.*' => ['integer']]);

        return $this->run(fn () => $sweep->preview($v['values']));
    }
}
