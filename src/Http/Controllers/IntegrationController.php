<?php

namespace TelescopeMonitor\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Telescope\Telescope;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Integrations\GitHubIssues;
use TelescopeMonitor\Integrations\IntegrationManagement;

final class IntegrationController
{
    private function run(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['telescope_monitor_private' => Telescope::withoutRecording($callback)], $status)->header('Cache-Control', 'private, no-store');
        } catch (Conflict $e) {
            return response()->json(['error' => ['code' => 'integration_conflict', 'message' => $e->getMessage()]], 409)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'integration_unavailable',
                    'message' => 'The integration operation is unavailable. Review current access, credentials and safe delivery history.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function configuration(IntegrationManagement $service): JsonResponse
    {
        return $this->run(fn () => $service->configuration());
    }

    public function links(string $issue, GitHubIssues $github): JsonResponse
    {
        return $this->run(fn () => $github->links($issue));
    }

    public function preview(Request $r, string $issue, GitHubIssues $github): JsonResponse
    {
        $v = $r->validate([
            'connection' => ['required', 'uuid'],
            'preview_key' => ['required', 'uuid'],
            'title' => ['nullable', 'string', 'max:256'],
            'labels' => ['sometimes', 'array', 'max:25'],
            'labels.*' => ['string', 'max:100', 'distinct'],
        ]);

        return $this->run(fn () => $github->preview($issue, $v));
    }

    public function command(Request $r, IntegrationManagement $service): JsonResponse
    {
        $r->validate(['client_key' => ['required', 'uuid']]);
        $v = $r->except(['client_key', '_token']);

        return $this->run(fn () => $service->command($r->route('integration_operation'), $r->route('connection') ?? $r->route('issue'), $r->input('client_key'), $v), $r->route('integration_operation') === 'test' ? 202 : 200);
    }
}
