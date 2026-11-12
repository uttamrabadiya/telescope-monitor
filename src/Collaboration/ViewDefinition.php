<?php

namespace TelescopeMonitor\Collaboration;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Http\Requests\InvestigationRequest;

final class ViewDefinition
{
    public const FILTERS = [
        'status',
        'environment',
        'origin',
        'class',
        'search',
        'tag',
        'first_after',
        'first_before',
        'last_after',
        'last_before',
        'include_tests',
        'assignee',
    ];

    public function __construct(private Assignments $assignments) {}

    public function validate(array $values): array
    {
        $v = Validator::make($values, [
            'name' => ['required', 'string', 'max:80'],
            'visibility' => ['required', Rule::in(['personal', 'shared'])],
            'filters' => ['present', 'array'],
            'sort' => ['required', Rule::in(['last_seen', 'first_seen', 'occurrences'])],
            'range' => ['required', Rule::in(['1h', '24h', '7d', '30d'])],
        ])->validate();
        if (array_diff(array_keys($v['filters']), self::FILTERS)) {
            throw ValidationException::withMessages(['filters' => 'Unsupported saved filters. Replace them with current inbox filters.']);
        }
        $rules = array_intersect_key((new InvestigationRequest)->rules(), array_flip(self::FILTERS));
        $filters = Validator::make($v['filters'], $rules)->validate();
        if (isset($filters['assignee']) && ! $this->assignments->enabled()) {
            throw ValidationException::withMessages(['filters.assignee' => 'Assignments are disabled. Remove the assignee filter or enable the host provider.']);
        }
        if (isset($filters['tag']) && ! in_array(explode(':', $filters['tag'], 2)[0], config('telescope-monitor.capture.allowed_tags', []), true)) {
            throw ValidationException::withMessages(['filters.tag' => 'This tag is no longer permitted.']);
        }
        if (isset($filters['include_tests'])) {
            $filters['include_tests'] = (bool) $filters['include_tests'];
        }ksort($filters);
        $v['filters'] = $filters;
        $v['name'] = trim($v['name']);
        if ($v['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Enter a view name.']);
        }

        return $v;
    }

    public function supported(int $version, array $values): bool
    {
        if ($version !== 1) {
            return false;
        }try {
            $this->validate($values);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function builtins(): array
    {
        $out = [
            'builtin:unresolved' => ['status' => 'unresolved'],
            'builtin:resolved' => ['status' => 'resolved'],
            'builtin:ignored' => ['status' => 'ignored'],
            'builtin:snoozed' => ['status' => 'snoozed'],
        ];
        if ($this->assignments->enabled()) {
            $out['builtin:assigned_to_me'] = ['status' => 'unresolved', 'assignee' => 'me'];
        }

        return $out;
    }
}
