<?php

namespace TelescopeMonitor\Collaboration;

use TelescopeMonitor\Data\Actor;

final class Assignments
{
    private array $cache = [];

    private ?\WeakReference $requestScope = null;

    public function enabled(): bool
    {
        $class = config('exception-monitor.assignments.directory');

        return (bool) config('exception-monitor.assignments.enabled', false) && is_string($class) && is_subclass_of($class, AssignmentDirectory::class);
    }

    public function configuration(): array
    {
        return ['enabled' => $this->enabled(), 'requested' => (bool) config('exception-monitor.assignments.enabled', false), 'configured' => is_string(config('exception-monitor.assignments.directory')) && is_subclass_of(config('exception-monitor.assignments.directory'), AssignmentDirectory::class), 'detail' => $this->enabled() ? 'Host assignment directory configured; availability is checked when used.' : 'Assignments require the deployment flag and a host directory adapter. Existing references are preserved.'];
    }

    private function provider(): AssignmentDirectory
    {
        if (! $this->enabled()) {
            throw new Unavailable('Assignments are disabled or the host directory is not configured.');
        }try {
            return app(config('exception-monitor.assignments.directory'));
        } catch (\Throwable) {
            throw new Unavailable('The host assignment directory is unavailable. Try again later.');
        }
    }

    private function valid(Actor $actor): bool
    {
        return $actor->key !== '' && mb_strlen($actor->key) <= 191 && mb_check_encoding($actor->key, 'UTF-8') && mb_check_encoding($actor->label, 'UTF-8') && $actor->label !== '' && mb_strlen($actor->label) <= 120;
    }

    public function search(string $query, int $limit = 25): array
    {
        try {
            $results = $this->provider()->search($query, min(25, max(1, $limit)));
            $out = [];
            foreach (array_slice($results, 0, $limit) as $actor) {
                if (! $actor instanceof Actor || ! $this->valid($actor)) {
                    throw new Unavailable('The host directory returned invalid identities.');
                }$out[$actor->key] = ['key' => $actor->key, 'label' => $actor->label];
            }

            return array_values($out);
        } catch (Unavailable $e) {
            throw $e;
        } catch (\Throwable) {
            throw new Unavailable('The host assignment directory is unavailable. Try again later.');
        }
    }

    public function eligible(string $key): Actor
    {
        try {
            $actor = $this->provider()->find($key);
            if (! $actor || $actor->key !== $key) {
                throw new Conflict('This assignee is no longer eligible. Refresh the directory.');
            }if (! $this->valid($actor)) {
                throw new Unavailable('The host directory returned an invalid identity.');
            }if (! $this->enabled()) {
                throw new Unavailable('Assignments were disabled. Reload this page.');
            }

            return $actor;
        } catch (Conflict|Unavailable $e) {
            throw $e;
        } catch (\Throwable) {
            throw new Unavailable('The host assignment directory is unavailable. Try again later.');
        }
    }

    public function describe(?string $key): ?array
    {
        $request = app('request');
        if ($this->requestScope?->get() !== $request) {
            $this->cache = [];
            $this->requestScope = \WeakReference::create($request);
        }
        if (! $this->enabled() || $key === null) {
            return null;
        }if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        try {
            $actor = $this->eligible($key);

            return $this->cache[$key] = ['key' => $key, 'label' => $actor->label, 'state' => 'available'];
        } catch (Conflict) {
            return $this->cache[$key] = ['key' => $key, 'label' => 'Unavailable assignee', 'state' => 'ineligible'];
        } catch (\Throwable) {
            return $this->cache[$key] = ['key' => $key, 'label' => 'Directory unavailable', 'state' => 'unavailable'];
        }
    }
}
