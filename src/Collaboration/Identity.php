<?php

namespace TelescopeMonitor\Collaboration;

use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Capture\CaptureKeys;
use TelescopeMonitor\Data\Actor;

final class Identity
{
    private array $labels = [];

    private ?\WeakReference $requestScope = null;

    public function __construct(private ActorResolver $actors, private CaptureKeys $keys) {}

    public function current(): Actor
    {
        $actor = $this->actors->current();
        abort_unless($actor !== null, 401);
        if ($actor->key === '' || mb_strlen($actor->key) > 191 || ! mb_check_encoding($actor->key, 'UTF-8')) {
            throw new Unavailable('The host actor identifier is unavailable.');
        }

        return $actor;
    }

    public function hash(string $key): string
    {
        return hash_hmac('sha256', 'collaboration.actor:'.$key, $this->keys->key()['value']);
    }

    public function digest(array $request): string
    {
        return hash_hmac('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->keys->key()['value']);
    }

    public function label(string $key): string
    {
        $request = app('request');
        if ($this->requestScope?->get() !== $request) {
            $this->labels = [];
            $this->requestScope = \WeakReference::create($request);
        }
        if (isset($this->labels[$key])) {
            return $this->labels[$key];
        }
        try {
            $current = $this->actors->current();
            if ($current?->key === $key) {
                return $this->labels[$key] = mb_substr($current->label ?: 'Host user', 0, 120);
            }
            $user = $this->actors->resolve(new Actor($key, ''));
            if (! $user) {
                return $this->labels[$key] = 'Former user';
            }$formatter = config('exception-monitor.actor_label');
            $label = is_callable($formatter) ? $formatter($user) : data_get($user, 'name');

            return $this->labels[$key] = is_string($label) && $label !== '' ? mb_substr($label, 0, 120) : 'Host user';
        } catch (\Throwable) {
            return $this->labels[$key] = 'Identity unavailable';
        }
    }
}
