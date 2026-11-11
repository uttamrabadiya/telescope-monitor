<?php

namespace TelescopeMonitor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $common = [
            'version' => ['required', 'integer', 'min:0'],
            'values' => ['required', 'array', 'min:1'],
        ];
        $group = $this->route('group');
        $specific = match ($group) {
            'general' => [
                'values' => ['required', 'array:display_name,dashboard_url,timezone', 'min:1'],
                'values.display_name' => ['sometimes', 'required', 'string', 'max:120'],
                'values.dashboard_url' => ['sometimes', 'url:http,https', 'max:2048'],
                'values.timezone' => ['sometimes', 'required', 'timezone'],
            ],
            'privacy' => [
                'values' => ['required', 'array:retain_stack_trace,retain_execution_context,retain_related_logs,identify_users,redact_patterns', 'min:1'],
                'values.retain_stack_trace' => ['sometimes', 'boolean'],
                'values.retain_execution_context' => ['sometimes', 'boolean'],
                'values.retain_related_logs' => ['sometimes', 'boolean'],
                'values.identify_users' => ['sometimes', 'boolean'],
                'values.redact_patterns' => ['sometimes', 'array', 'max:20'],
                'values.redact_patterns.*' => ['required', 'string', 'max:512', function ($attribute, $value, $fail) {
                    if (@preg_match($value, '') === false) {
                        $fail('Enter a valid regular expression, including delimiters.');
                    }
                }],
            ],
            'retention' => [
                'values' => ['required', 'array:occurrence_days,delivery_days,aggregate_days,activity_days', 'min:1'],
                'values.occurrence_days' => ['sometimes', 'integer', 'between:1,90'],
                'values.delivery_days' => ['sometimes', 'integer', 'between:1,90'],
                'values.aggregate_days' => ['sometimes', 'integer', 'between:30,365'],
                'values.activity_days' => ['sometimes', 'integer', 'between:1,365'],
            ],
            default => [
                'group' => ['required', Rule::in([])],
            ],
        };

        return array_replace($common, $specific);
    }
}
