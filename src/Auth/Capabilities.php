<?php

namespace TelescopeMonitor\Auth;

use Illuminate\Support\Facades\Gate;
use TelescopeMonitor\Data\Actor;

final class Capabilities
{
    public function __construct(private ActorResolver $actors) {}

    public function allows(string $capability, ?Actor $actor = null): bool
    {
        $actor ??= $this->actors->current();
        if (! $actor || ! ($user = $this->actors->resolve($actor))) {
            return false;
        }
        $gate = config('exception-monitor.capabilities.'.$capability);

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
