<?php

namespace TelescopeMonitor\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Telescope\Telescope;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Http\Requests\AlertCommandRequest;
use TelescopeMonitor\Repositories\Contracts\AlertManagement;

final class AlertController
{
    private function run(callable $work, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['exception_monitor_private' => Telescope::withoutRecording($work)], $status)->header('Cache-Control', 'private, no-store');
        } catch (Conflict $e) {
            return response()->json(['error' => ['code' => 'alert_conflict', 'message' => $e->getMessage(), 'current' => $e->current]], 409)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            return response()->json(['error' => ['code' => 'alerting_unavailable', 'message' => 'Alert configuration or delivery data is unavailable. Review storage, encryption and the host mailer.']], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function configuration(AlertManagement $alerts): JsonResponse
    {
        return $this->run(fn () => $alerts->configuration());
    }

    public function history(Request $r, AlertManagement $alerts): JsonResponse
    {
        $v = $r->validate(['status' => ['sometimes', Rule::in(['pending', 'processing', 'retrying', 'sent', 'cancelled', 'failed', 'needs_review'])], 'test' => ['sometimes', 'boolean'], 'issue' => ['sometimes', 'uuid'], 'rule' => ['sometimes', 'uuid'], 'channel' => ['sometimes', Rule::in(['email', 'slack', 'webhook', 'github'])], 'environment' => ['sometimes', 'string', 'max:64'], 'after' => ['sometimes', 'date_format:Y-m-d'], 'before' => ['sometimes', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return $this->run(fn () => $alerts->history($v));
    }

    public function detail(string $delivery, AlertManagement $alerts): JsonResponse
    {
        return $this->run(fn () => $alerts->detail($delivery));
    }

    public function operation(string $operation, AlertManagement $alerts): JsonResponse
    {
        return $this->run(fn () => $alerts->operation($operation));
    }

    public function preview(Request $r, AlertManagement $alerts): JsonResponse
    {
        $v = $r->validate(['definition' => ['required', 'array'], 'issue' => ['nullable', 'uuid']]);

        return $this->run(fn () => $alerts->preview($v['definition'], $v['issue'] ?? null));
    }

    public function review(string $delivery, AlertManagement $alerts): JsonResponse
    {
        return $this->run(fn () => $alerts->reviewPreview($delivery));
    }

    public function command(AlertCommandRequest $r, AlertManagement $alerts): JsonResponse
    {
        $v = $r->validated();
        $key = $v['client_key'];
        unset($v['client_key']);

        return $this->run(fn () => $alerts->command($r->route('alert_operation'), $r->route('rule') ?? $r->route('connection') ?? $r->route('delivery'), $key, $v), $r->route('alert_operation') === 'test' ? 202 : 200);
    }
}
