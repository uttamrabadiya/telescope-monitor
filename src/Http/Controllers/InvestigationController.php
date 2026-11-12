<?php

namespace TelescopeMonitor\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Laravel\Telescope\Telescope;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Capture\CaptureKeys;
use TelescopeMonitor\Collaboration\Conflict;
use TelescopeMonitor\Collaboration\ViewResolver;
use TelescopeMonitor\Http\Requests\InvestigationRequest;
use TelescopeMonitor\Http\Requests\LifecycleRequest;
use TelescopeMonitor\Investigation\LifecycleConflict;
use TelescopeMonitor\Investigation\Window;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\InvestigationRepository;
use TelescopeMonitor\Repositories\Contracts\LifecycleRepository;

final class InvestigationController
{
    public function __construct(private InvestigationRepository $queries, private InstallationRepository $installations, private CaptureInboxRepository $inbox) {}

    private function ready(): int
    {
        abort_unless($this->inbox->ready(), 409);

        return $this->installations->current()->id;
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['exception_monitor_private' => $data], $status)->header('Cache-Control', 'private, no-store');
    }

    public function inbox(InvestigationRequest $r): JsonResponse
    {
        $id = $this->ready();

        return $this->read(function () use ($id, $r) {
            $filters = app(ViewResolver::class)->resolve($r->validated());

            return $this->queries->inbox($id, $filters, new Window($filters['range'] ?? '24h'));
        });
    }

    public function overview(InvestigationRequest $r): JsonResponse
    {
        $id = $this->ready();

        return $this->read(fn () => $this->queries->overview($id, $r->validated(), new Window($r->input('range', '24h'))));
    }

    public function detail(InvestigationRequest $r, string $issue): JsonResponse
    {
        $id = $this->ready();

        return $this->read(function () use ($id, $r, $issue) {
            $data = $this->queries->detail($id, $issue, $r->validated(), new Window($r->input('range', '24h')));
            abort_unless($data !== null, 404);

            return $data;
        });
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'monitoring_unavailable',
                'message' => 'Monitoring data could not be loaded. Review package storage and try again.',
            ],
        ], 503)->header('Cache-Control', 'private, no-store');
    }

    private function read(callable $query): JsonResponse
    {
        try {
            return $this->response($query());
        } catch (HttpExceptionInterface|ModelNotFoundException $e) {
            throw $e;
        } catch (Conflict $e) {
            return response()->json(['error' => ['code' => 'view_needs_repair', 'message' => $e->getMessage()]], 409)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable) {
            return $this->unavailable();
        }
    }

    public function act(LifecycleRequest $r, string $issue, LifecycleRepository $lifecycle, ActorResolver $actors, CaptureKeys $keys): JsonResponse
    {
        $this->ready();
        $actor = $actors->current();
        abort_unless($actor !== null, 401);
        $data = $r->validated();
        unset($data['client_key']);
        $data['version'] = (int) $data['version'];
        if (isset($data['until'])) {
            $data['until'] = CarbonImmutable::parse($data['until'])->utc()->toISOString();
        }
        $key = $keys->key()['value'];
        $digest = hash_hmac('sha256', json_encode(['issue' => $issue, 'request' => $data], JSON_THROW_ON_ERROR), $key);
        try {
            return $this->response(Telescope::withoutRecording(fn () => $lifecycle->act($issue, $actor->key, hash_hmac('sha256', 'lifecycle:'.$actor->key, $key), $r->string('client_key')->toString(), $digest, $data)));
        } catch (LifecycleConflict $e) {
            return response()->json(['error' => ['code' => 'lifecycle_conflict', 'message' => $e->getMessage(), 'current' => $e->current]], 409)->header('Cache-Control', 'private, no-store');
        } catch (HttpExceptionInterface|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable) {
            return $this->unavailable();
        }

    }
}
