<?php

namespace TelescopeMonitor\Alerts;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Collaboration\Assignments;

final class RuleDefinition
{
    public function validate(array $v): array
    {
        $data = Validator::make($v, [
            'name' => ['required', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'],
            'events' => ['required', 'array', 'min:1', 'max:12'],
            'events.*' => ['required', Rule::in(array_keys(EventCatalog::LABELS)), 'distinct'],
            'conditions' => ['required', 'array:environments,class,origin,tag'],
            'conditions.environments' => ['required', 'array', 'min:1', 'max:20'],
            'conditions.environments.*' => [
                'required',
                'string',
                'max:64',
                Rule::in(config('exception-monitor.alerts.allowed_environments', ['production'])),
                'distinct',
            ],
            'conditions.class' => ['nullable', 'string', 'max:512'],
            'conditions.origin' => ['nullable', Rule::in(['web', 'job', 'command', 'unknown'])],
            'conditions.tag' => ['nullable', 'string', 'max:129'],
            'connections' => ['present', 'array', 'max:25'],
            'connections.*' => ['uuid', 'distinct'],
            'threshold_count' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('exception-monitor.alerts.max_threshold_count', 100000))],
            'window_seconds' => ['nullable', Rule::in([300, 900, 3600])],
            'cooldown_seconds' => [
                'required',
                'integer',
                'min:0',
                'max:'.max(0, min(86400, (int) config('exception-monitor.alerts.max_cooldown_seconds', 86400))),
            ],
        ])->validate();
        $data['enabled'] = (bool) $data['enabled'];
        $data['cooldown_seconds'] = (int) $data['cooldown_seconds'];
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Enter a rule name.']);
        }
        if ($data['enabled'] && ! $data['connections']) {
            throw ValidationException::withMessages(['connections' => 'Choose at least one email recipient before enabling.']);
        }
        if (in_array('error.threshold_reached', $data['events'], true)) {
            if (! isset($data['threshold_count'],$data['window_seconds'])) {
                throw ValidationException::withMessages(['threshold_count' => 'Choose a positive threshold and a fixed UTC window.']);
            }$data['threshold_count'] = (int) $data['threshold_count'];
            $data['window_seconds'] = (int) $data['window_seconds'];
        } else {
            $data['threshold_count'] = null;
            $data['window_seconds'] = null;
        }
        if (in_array('assignment.changed', $data['events'], true) && ! app(Assignments::class)->enabled()) {
            throw ValidationException::withMessages(['events' => 'Assignment notifications require the host assignment adapter.']);
        }
        foreach (['class', 'origin', 'tag'] as $key) {
            $data['conditions'][$key] = $data['conditions'][$key] ?? null;
        }
        if ($data['conditions']['tag'] && ! in_array(explode(':', $data['conditions']['tag'], 2)[0], config('exception-monitor.capture.allowed_tags', []), true)) {
            throw ValidationException::withMessages(['conditions.tag' => 'Choose a deployment-permitted tag.']);
        }
        ksort($data['conditions']);
        sort($data['events']);
        sort($data['connections']);
        sort($data['conditions']['environments']);

        return $data;
    }

    public function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || ! mb_check_encoding($email, 'ASCII') || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Enter one valid ASCII email address without a display name.']);
        }

        return $email;
    }

    public function mask(string $email): string
    {
        [$name,$domain] = explode('@', $email, 2);

        return substr($name, 0, 1).'***@'.$domain;
    }
}
