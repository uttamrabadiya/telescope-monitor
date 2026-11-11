<?php

namespace TelescopeMonitor\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Telescope\Telescope;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use TelescopeMonitor\Collaboration\Assignments;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Http\Requests\CollaborationRequest;
use TelescopeMonitor\Repositories\Contracts\CollaborationRepository;

final class CollaborationController
{
    private function response(array $data): JsonResponse
    {
        return response()->json(['exception_monitor_private' => $data])->header('Cache-Control', 'private, no-store');
    }

    private function run(callable $work): JsonResponse
    {
        try {
            return $this->response(Telescope::withoutRecording($work));
        } catch (Conflict $e) {
            return response()->json(['error' => ['code' => 'collaboration_conflict', 'message' => $e->getMessage(), 'current' => $e->current]], 409)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            return response()->json(['error' => ['code' => 'collaboration_unavailable', 'message' => 'Collaboration or the host directory is unavailable. Your draft is preserved; try again.']], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function notes(Request $r, string $issue, CollaborationRepository $collaboration): JsonResponse
    {
        $v = $r->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return $this->run(fn () => $collaboration->notes($issue, (int) ($v['page'] ?? 1)));
    }

    public function views(Request $r, CollaborationRepository $collaboration): JsonResponse
    {
        $v = $r->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return $this->run(fn () => $collaboration->views((int) ($v['page'] ?? 1)));
    }

    public function view(string $view, CollaborationRepository $collaboration): JsonResponse
    {
        return $this->run(fn () => $collaboration->view($view));
    }

    public function preferences(CollaborationRepository $collaboration): JsonResponse
    {
        return $this->run(fn () => $collaboration->preferences());
    }

    public function directory(Request $r, Assignments $assignments): JsonResponse
    {
        $v = $r->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);

        return $this->run(fn () => ['items' => $assignments->search($v['q'] ?? '', 25)]);
    }

    public function command(CollaborationRequest $r, CollaborationRepository $collaboration): JsonResponse
    {
        $values = $r->validated();
        $key = $values['client_key'];
        unset($values['client_key']);
        if (isset($values['exception_monitor_private'])) {
            $values['body'] = $values['exception_monitor_private']['body'];
            unset($values['exception_monitor_private']);
        }
        if ($r->route('note')) {
            $values['note'] = $r->route('note');
        }

        return $this->run(fn () => $collaboration->command($r->route('collaboration_operation'), $r->route('issue') ?? $r->route('view'), $key, $values));
    }
}
