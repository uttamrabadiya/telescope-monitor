<?php

namespace TelescopeMonitor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use TelescopeMonitor\Collaboration\Assignments;

final class InvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'view' => ['sometimes', 'uuid'], 'modified' => ['sometimes', 'boolean'],
            'assignee' => ['sometimes', 'string', 'max:197'],
            'range' => ['sometimes', Rule::in(['1h', '24h', '7d', '30d'])], 'environment' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(['all', 'unresolved', 'resolved', 'ignored', 'snoozed'])],
            'origin' => ['sometimes', Rule::in(['web', 'job', 'command', 'unknown'])], 'class' => ['sometimes', 'string', 'max:512'],
            'search' => ['sometimes', 'string', 'max:200'], 'tag' => ['sometimes', 'string', 'max:129'],
            'first_after' => ['sometimes', 'date_format:Y-m-d'], 'first_before' => ['sometimes', 'date_format:Y-m-d'],
            'last_after' => ['sometimes', 'date_format:Y-m-d'], 'last_before' => ['sometimes', 'date_format:Y-m-d'],
            'sort' => ['sometimes', Rule::in(['last_seen', 'first_seen', 'occurrences'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'per_page' => ['sometimes', Rule::in([25, 50, 100])],
            'include_tests' => ['sometimes', 'boolean'], 'occurrence' => ['sometimes', 'uuid'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (array_diff(array_keys($this->query()), array_keys($this->rules()))) {
                $v->errors()->add('filters', 'Unsupported filters. Clear them and try again.');
            }
            if ($this->filled('assignee') && ! app(Assignments::class)->enabled()) {
                $v->errors()->add('assignee', 'Assignments are disabled.');
            }
            if ($this->filled('tag')) {
                $key = explode(':', $this->string('tag')->toString(), 2)[0];
                if (! in_array($key, config('exception-monitor.capture.allowed_tags', []), true)) {
                    $v->errors()->add('tag', 'This tag is not permitted by deployment policy.');
                }
            }
        });
    }
}
