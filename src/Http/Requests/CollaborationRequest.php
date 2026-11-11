<?php

namespace TelescopeMonitor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CollaborationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $op = $this->route('collaboration_operation');
        $rules = ['client_key' => ['required', 'uuid']];
        if (! in_array($op, ['note.create', 'view.create'], true)) {
            $rules['version'] = ['required', 'integer', 'min:'.($op === 'preferences' ? '0' : '1')];
        }
        if (in_array($op, ['note.create', 'note.update'], true)) {
            $rules['exception_monitor_private'] = ['required', 'array:body'];
            $rules['exception_monitor_private.body'] = ['required', 'string', 'max:10000'];
        }
        if (in_array($op, ['view.create', 'view.update', 'view.rename'], true)) {
            $rules['name'] = ['required', 'string', 'max:80'];
        }
        if (in_array($op, ['view.create', 'view.update'], true)) {
            $rules['visibility'] = ['required', Rule::in(['personal', 'shared'])];
            $rules['filters'] = ['present', 'array'];
            $rules['sort'] = ['required', Rule::in(['last_seen', 'first_seen', 'occurrences'])];
            $rules['range'] = ['required', Rule::in(['1h', '24h', '7d', '30d'])];
        }
        if ($op === 'preferences') {
            $rules += ['shortcuts' => ['present', 'array', 'max:100'], 'shortcuts.*' => ['array:ref,favorite,position'], 'shortcuts.*.ref' => ['required', 'string', 'max:64', 'distinct:strict'], 'shortcuts.*.favorite' => ['required', 'boolean'], 'shortcuts.*.position' => ['required', 'integer', 'min:0', 'max:99', 'distinct'], 'default_view_ref' => ['present', 'nullable', 'string', 'max:64']];
        }
        if ($op === 'assignment') {
            $rules['assignee'] = ['present', 'nullable', 'string', 'max:191'];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $top = array_unique(array_map(fn ($key) => explode('.', $key)[0], array_keys($this->rules())));
            if (array_diff(array_keys($this->all()), array_merge($top, ['_token']))) {
                $v->errors()->add('request', 'Unsupported fields in this request.');
            }
        });
    }
}
