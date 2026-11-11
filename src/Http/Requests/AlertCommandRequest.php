<?php

namespace TelescopeMonitor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use TelescopeMonitor\Alerts\EventCatalog;

final class AlertCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $op = $this->route('alert_operation');
        $r = ['client_key' => ['required', 'uuid']];
        if (in_array($op, ['rule.update', 'rule.delete', 'connection.update', 'connection.disconnect'], true)) {
            $r['version'] = ['required', 'integer', 'min:1'];
        }
        if (in_array($op, ['connection.create', 'connection.update'], true)) {
            $r += ['name' => ['required', 'string', 'max:120'], 'enabled' => ['required', 'boolean']];
        }
        if ($op === 'connection.create') {
            $r += ['email' => ['required', 'string', 'max:254'], 'replaces' => ['nullable', 'uuid'], 'replaces_version' => ['required_with:replaces', 'integer', 'min:1']];
        }
        if (in_array($op, ['rule.create', 'rule.update'], true)) {
            $r += ['name' => ['required', 'string', 'max:120'], 'enabled' => ['required', 'boolean'], 'events' => ['required', 'array', 'min:1', 'max:12'], 'events.*' => [Rule::in(array_keys(EventCatalog::LABELS))], 'conditions' => ['required', 'array:environments,class,origin,tag'], 'connections' => ['present', 'array', 'max:25'], 'threshold_count' => ['nullable', 'integer'], 'window_seconds' => ['nullable', 'integer'], 'cooldown_seconds' => ['required', 'integer']];
        }
        if ($op === 'test') {
            $r['connections'] = ['required', 'array', 'min:1', 'max:25'];
        }
        if ($op === 'test') {
            $r['connections.*'] = ['required', 'uuid', 'distinct'];
        }
        if ($op === 'resend') {
            $r['preview_digest'] = ['required', 'regex:/^[a-f0-9]{64}$/D'];
        }

        return $r;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $top = array_unique(array_map(fn ($key) => explode('.', $key)[0], array_keys($this->rules())));
            if (array_diff(array_keys($this->all()), array_merge($top, ['_token']))) {
                $v->errors()->add('request', 'Unsupported alert configuration fields.');
            }
        });
    }
}
