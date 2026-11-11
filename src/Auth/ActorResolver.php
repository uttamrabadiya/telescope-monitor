<?php

namespace TelescopeMonitor\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use TelescopeMonitor\Data\Actor;

interface ActorResolver
{
    public function current(): ?Actor;

    public function resolve(Actor $actor): ?Authenticatable;
}
