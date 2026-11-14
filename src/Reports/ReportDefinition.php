<?php

namespace TelescopeMonitor\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Models\Connection;

final class ReportDefinition
{
    public static function defaults(): array
    {
        $base = [
            'enabled' => false,
            'connections' => [],
            'environments' => [],
            'time' => '09:00',
            'timezone' => (string) config('app.timezone', 'UTC'),
            'skip_empty' => true,
        ];

        return ['daily' => $base, 'weekly' => $base + ['weekday' => 1]];
    }

    public function validate(array $values, int $installation): array
    {
        $rules = ['values' => ['required', 'array:daily,weekly', 'min:1']];
        foreach (['daily', 'weekly'] as $kind) {
            if (! array_key_exists($kind, $values)) {
                continue;
            }
            $p = 'values.'.$kind;
            $rules[$p] = [
                'sometimes',
                'required',
                'array:enabled,connections,environments,time,timezone,skip_empty'.($kind === 'weekly' ? ',weekday' : ''),
            ];
            foreach (['enabled', 'skip_empty'] as $key) {
                $rules[$p.'.'.$key] = ['required', 'boolean'];
            }
            $rules[$p.'.connections'] = ['present', 'array', 'max:25'];
            $rules[$p.'.connections.*'] = ['required', 'uuid', 'distinct'];
            $rules[$p.'.environments'] = ['present', 'array', 'max:25'];
            $rules[$p.'.environments.*'] = [
                'required',
                'string',
                'max:64',
                'distinct',
                Rule::in(config('telescope-monitor.alerts.allowed_environments', ['production'])),
            ];
            $rules[$p.'.time'] = ['required', 'date_format:H:i'];
            $rules[$p.'.timezone'] = ['required', 'timezone'];
            if ($kind === 'weekly') {
                $rules[$p.'.weekday'] = ['required', 'integer', 'between:1,7'];
            }
        }
        $values = Validator::make(['values' => $values], $rules)->validate()['values'];
        $out = [];
        foreach ($values as $kind => $v) {
            $ids = $v['connections'];
            $count = Connection::query()->where('installation_id', $installation)->where('provider', 'email')->where('enabled', true)->whereNull('disconnected_at')->whereIn('public_id', $ids)->count();
            if ($count !== count($ids) || ($v['enabled'] && (! $ids || ! $v['environments']))) {
                throw ValidationException::withMessages([
                    'values.'.$kind => 'Choose available email recipients and at least one allowed environment before enabling this report.',
                ]);
            }
            sort($ids);
            $env = $v['environments'];
            sort($env);
            $out[$kind] = [
                'enabled' => (bool) $v['enabled'],
                'connections' => $ids,
                'environments' => $env,
                'time' => $v['time'],
                'timezone' => $v['timezone'],
                'skip_empty' => (bool) $v['skip_empty'],
            ];
            if ($kind === 'weekly') {
                $out[$kind]['weekday'] = (int) $v['weekday'];
            }
        }

        return $out;
    }

    public static function hash(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
    }
}
