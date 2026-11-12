<?php

namespace TelescopeMonitor\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_key' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['resolve', 'ignore', 'reopen', 'snooze', 'unsnooze'])],
            'until' => [
                'required_if:action,snooze',
                'prohibited_unless:action,snooze',
                'nullable',
                'date',
                'regex:/T.*(?:Z|[+-]\\d{2}:\\d{2})$/i',
                'after:now',
                'before:'.CarbonImmutable::now('UTC')->addYear()->toISOString(),
            ],
        ];
    }
}
