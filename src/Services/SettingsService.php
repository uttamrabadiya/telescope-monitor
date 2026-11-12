<?php

namespace TelescopeMonitor\Services;

use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Foundation\ConfigurationConflict;
use TelescopeMonitor\Foundation\PackagePath;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\SettingRepository;
use TelescopeMonitor\Storage\Database;

final class SettingsService
{
    public function __construct(
        private InstallationRepository $installations,
        private SettingRepository $settings,
        private ActorResolver $actors,
        private PackagePath $path,
    ) {}

    public function defaults(string $group): array
    {
        return match ($group) {
            'general' => [
                'display_name' => (string) config('app.name', 'Laravel'),
                'dashboard_url' => rtrim((string) config('app.url'), '/').$this->path->base(),
                'timezone' => (string) config('app.timezone', 'UTC'),
            ],
            'privacy' => [
                'retain_stack_trace' => true,
                'retain_execution_context' => true,
                'retain_related_logs' => true,
                'retain_sql_templates' => (bool) config('telescope-monitor.capture.sql_templates', false),
                'identify_users' => false,
                'redact_patterns' => [],
            ],
            'retention' => [
                'occurrence_days' => 30,
                'delivery_days' => 30,
                'aggregate_days' => 90,
                'activity_days' => 90,
            ],
            default => throw ValidationException::withMessages([
                'group' => 'Unknown settings group.',
            ]),
        };
    }

    public function group(string $group): array
    {
        $defaults = $this->defaults($group);
        $installation = $this->installations->current();
        $setting = $this->settings->find($installation->id, $group);
        $values = array_replace($defaults, $setting->values ?? []);
        $metadata = [];
        foreach ($defaults as $key => $default) {
            $lock = $this->lock($group, $key);
            if ($lock !== null) {
                $values[$key] = $lock['value'];
            }
            $metadata[$key] = [
                'locked' => $lock !== null,
                'source' => $lock !== null ? 'deployment' : (array_key_exists($key, $setting->values ?? []) ? 'database' : 'default'),
                'reason' => $lock['reason'] ?? null,
            ];
        }

        if ($group === 'retention') {
            $policy = app(RetentionPolicy::class);
            $unbounded = $values;
            $values = $policy->effective($values);
            foreach ($policy->bounds() as $key => $bounds) {
                $metadata[$key] += $bounds;
                if (! $metadata[$key]['locked'] && (int) $unbounded[$key] !== $values[$key]) {
                    $metadata[$key]['source'] = 'deployment';
                }
                if (! $metadata[$key]['locked']) {
                    $metadata[$key]['reason'] = 'telescope-monitor.retention_limits.'.$key;
                }
            }
        }

        return [
            'group' => $group,
            'values' => $values,
            'fields' => $metadata,
            'version' => $setting->row_version ?? 0,
            'config_revision' => $installation->config_revision,
        ];
    }

    public function save(string $group, array $values, int $version): array
    {
        $defaults = $this->defaults($group);
        if ($group === 'retention') {
            $values = app(RetentionPolicy::class)->validate($values);
        }
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                throw ValidationException::withMessages([
                    'values.'.$key => 'Unknown setting.',
                ]);
            }
            if ($this->lock($group, $key) !== null) {
                throw ValidationException::withMessages([
                    'values.'.$key => 'This setting is managed by deployment configuration.',
                ]);
            }
        }
        foreach ($values as $key => $value) {
            if ($group === 'privacy' && $key !== 'redact_patterns') {
                $values[$key] = (bool) $value;
            } elseif ($group === 'retention') {
                $values[$key] = (int) $value;
            }
        }
        Database::connection()->transaction(function () use ($group, $values, $version) {
            $installation = $this->installations->lock();
            if ($installation->state !== 'active') {
                throw new ConfigurationConflict('Maintenance is in progress. Preferences were not changed.');
            }
            foreach ($values as $key => $value) {
                if ($this->lock($group, $key) !== null) {
                    throw ValidationException::withMessages(['values.'.$key => 'This setting is managed by deployment configuration.']);
                }
            }
            $stored = $this->settings->find($installation->id, $group);
            if (($stored->row_version ?? 0) !== $version) {
                throw new ConfigurationConflict('Settings changed since you opened them. Your draft has not been saved.');
            }
            $actor = $this->actors->current();
            if (! $actor) {
                throw new ConfigurationConflict('The authenticated actor is no longer available.');
            }
            if ($group === 'retention') {
                $policy = app(RetentionPolicy::class);
                $values = $policy->validate($values);
                $old = $policy->effective($stored->values ?? []);
                $policy->remember($installation, $old, $policy->effective(array_replace($old, $values)));
            }
            $this->settings->write($installation->id, $group, array_replace($stored->values ?? [], $values), $actor->key);
            $this->installations->advanceConfiguration($installation);
        }, 5);

        return $this->group($group);
    }

    private function lock(string $group, string $key): ?array
    {
        $locks = config('telescope-monitor.locked', []);
        if (array_key_exists($group.'.'.$key, $locks)) {
            return [
                'value' => $locks[$group.'.'.$key],
                'reason' => 'telescope-monitor.locked.'.$group.'.'.$key,
            ];
        }
        if ($group === 'general' && $key === 'dashboard_url') {
            return [
                'value' => $this->defaults('general')['dashboard_url'],
                'reason' => 'app.url and telescope-monitor.path',
            ];
        }

        return null;
    }
}
