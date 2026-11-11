<?php

namespace TelescopeMonitor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RedactionPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exception_monitor_private' => ['required', 'array:text,patterns'],
            'exception_monitor_private.text' => ['required', 'string', function ($attribute, $value, $fail) {
                if (strlen($value) > 16384) {
                    $fail('Limit the sample to 16 KiB of UTF-8 text.');
                }
            }],
            'exception_monitor_private.patterns' => ['sometimes', 'array', 'max:20'],
            'exception_monitor_private.patterns.*' => ['string', 'max:512', function ($attribute, $value, $fail) {
                if (@preg_match($value, '') === false) {
                    $fail('Enter a valid regular expression, including delimiters.');
                }
            }],
        ];
    }
}
