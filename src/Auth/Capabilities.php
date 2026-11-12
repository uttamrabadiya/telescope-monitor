<?php

namespace TelescopeMonitor\Auth;

use Illuminate\Support\Facades\Gate;
use TelescopeMonitor\Data\Actor;

final class Capabilities
{
    public function __construct(private ActorResolver $actors) {}

    public function allows(string $capability, ?Actor $actor = null): bool
    {
        if (! in_array($capability, ['view', 'operate', 'manage'], true)) {
            return false;
        }
        $current = $this->actors->current();
        $actor ??= $current;
        if (! $actor || ! ($user = $this->actors->resolve($actor))) {
            return false;
        }
        $driver = config('telescope-monitor.authorization.driver', 'telescope');
        if ($driver === 'telescope') {
            return app(TelescopeAccess::class)->allows($user, $actor->key === TelescopeAccess::SHARED_KEY, $current?->key === $actor->key);
        }
        if ($driver !== 'gates') {
            return false;
        }
        $gate = config('telescope-monitor.capabilities.'.$capability);

        return is_string($gate) && Gate::has($gate) && Gate::forUser($user)->allows($gate);
    }

    public function current(): array
    {
        return [
            'view' => $this->allows('view'),
            'operate' => $this->allows('operate'),
            'manage' => $this->allows('manage'),
        ];
    }
}
