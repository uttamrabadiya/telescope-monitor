<?php

namespace TelescopeMonitor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use TelescopeMonitor\Foundation\ConfigurationConflict;
use TelescopeMonitor\Http\Requests\RedactionPreviewRequest;
use TelescopeMonitor\Http\Requests\UpdateSettingsRequest;
use TelescopeMonitor\Operations\OperationalStatus;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Services\BootstrapService;
use TelescopeMonitor\Services\HealthService;
use TelescopeMonitor\Services\RedactionService;
use TelescopeMonitor\Services\SettingsService;
use Throwable;

final class ApiController
{
    public function bootstrap(BootstrapService $bootstrap): JsonResponse
    {
        try {
            return $this->response($bootstrap->read());
        } catch (Throwable) {
            return $this->failure('storage_unavailable', 'Monitoring storage could not be read. Review the host database configuration.', 503);
        }
    }

    public function settings(string $group, SettingsService $settings): JsonResponse
    {
        return $this->response($settings->group($group));
    }

    public function update(UpdateSettingsRequest $request, string $group, SettingsService $settings): JsonResponse
    {
        try {
            return $this->response($settings->save($group, $request->validated()['values'], (int) $request->validated()['version']));
        } catch (ConfigurationConflict $exception) {
            return $this->failure('version_conflict', $exception->getMessage(), 409);
        }
    }

    public function health(HealthService $health): JsonResponse
    {
        return $this->response($health->inspect());
    }

    public function check(HealthService $health): JsonResponse
    {
        return $this->response($health->inspect(true));
    }

    public function setup(Request $request, InstallationRepository $installations): JsonResponse
    {
        $data = $request->validate([
            'step' => ['required', 'integer', 'between:0,7'],
            'acknowledge' => ['sometimes', Rule::in(['application', 'privacy', 'operations'])],
            'config_revision' => ['required_with:acknowledge', 'integer', 'min:1'],
            'notification_choice' => ['sometimes', Rule::in(['verify', 'skip'])],
        ]);
        try {
            $result = app(OperationalStatus::class)->acknowledge($data);

            return $this->response(['step' => $data['step'], 'status' => $result]);
        } catch (ConfigurationConflict $e) {
            return $this->failure('version_conflict', $e->getMessage(), 409);
        }
    }

    public function redaction(RedactionPreviewRequest $request, RedactionService $redaction, SettingsService $settings): JsonResponse
    {
        $values = $request->validated()['telescope_monitor_private'];
        $patterns = $values['patterns'] ?? $settings->group('privacy')['values']['redact_patterns'];

        return response()->json([
            'telescope_monitor_private' => [
                'text' => $redaction->text($values['text'], $patterns),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function notFound(): JsonResponse
    {
        return $this->failure('not_found', 'This monitoring endpoint does not exist.', 404);
    }

    private function response(array $data): JsonResponse
    {
        return response()->json([
            'data' => $data,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function failure(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status)->header('Cache-Control', 'private, no-store');
    }
}
