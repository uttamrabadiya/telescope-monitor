<?php

namespace TelescopeMonitor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TelescopeMonitor\Capture\CaptureProbes;
use TelescopeMonitor\Capture\EvidenceReader;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;

final class CaptureController
{
    public function probe(Request $request, CaptureProbes $probes, CaptureInboxRepository $inbox): JsonResponse
    {
        abort_unless($inbox->ready(), 409);
        $data = $request->validate(['client_key' => ['required', 'uuid']]);

        return response()->json(['data' => $probes->request($data['client_key'])], 202)->header('Cache-Control', 'private, no-store');
    }

    public function status(string $operation, CaptureProbes $probes, CaptureInboxRepository $inbox): JsonResponse
    {
        abort_unless($inbox->ready(), 409);
        $data = $probes->status($operation);
        abort_unless($data !== null, 404);

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function evidence(string $occurrence, EvidenceReader $reader, CaptureInboxRepository $inbox): JsonResponse
    {
        abort_unless($inbox->ready(), 409);
        $data = $reader->read($occurrence);
        abort_unless($data !== null, 404);

        return response()->json(['exception_monitor_private' => $data])->header('Cache-Control', 'private, no-store');
    }
}
