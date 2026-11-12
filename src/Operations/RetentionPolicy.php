<?php

namespace TelescopeMonitor\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use TelescopeMonitor\Models\Installation;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\SettingRepository;
use TelescopeMonitor\Storage\Database;

final class RetentionPolicy
{
    public const DEFAULTS = ['occurrence_days' => 30, 'delivery_days' => 30, 'aggregate_days' => 90, 'activity_days' => 90];

    public function __construct(private InstallationRepository $installations, private SettingRepository $settings) {}

    public function ready(): bool
    {
        return Database::schema()->hasTable('telescope_monitor_purge_runs');
    }

    public function bounds(): array
    {
        $bounds = [];
        foreach (['occurrence_days' => [1, 90], 'delivery_days' => [1, 90], 'aggregate_days' => [30, 365], 'activity_days' => [1, 365]] as $key => [$min,$max]) {
            $configured = config('telescope-monitor.retention_limits.'.$key, []);
            if (! is_array($configured) || filter_var($configured['min'] ?? $min, FILTER_VALIDATE_INT) === false || filter_var($configured['max'] ?? $max, FILTER_VALIDATE_INT) === false) {
                throw new MaintenanceFailure('invalid_retention_limits');
            }
            $lower = max($min, (int) ($configured['min'] ?? $min));
            $upper = min($max, (int) ($configured['max'] ?? $max));
            if ($lower > $upper) {
                throw new MaintenanceFailure('invalid_retention_limits');
            }
            $bounds[$key] = ['min' => $lower, 'max' => $upper];
        }

        return $bounds;
    }

    public function effective(?array $values = null): array
    {
        $values = $values ?? ($this->settings->find($this->installations->current()->id, 'retention')->values ?? []);
        $out = array_replace(self::DEFAULTS, $values);
        $locks = config('telescope-monitor.locked', []);
        foreach ($this->bounds() as $key => $b) {
            $out[$key] = max($b['min'], min($b['max'], (int) ($locks['retention.'.$key] ?? $out[$key])));
        }

        return array_intersect_key($out, self::DEFAULTS);
    }

    public function validate(array $v): array
    {
        $out = [];
        foreach ($v as $key => $value) {
            $b = $this->bounds()[$key] ?? null;
            if (! $b || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $b['min'] || (int) $value > $b['max']) {
                throw ValidationException::withMessages(['values.'.$key => 'Choose a retention value within its deployment limits.']);
            }$out[$key] = (int) $value;
        }

        return $out;
    }

    public function floors(?array $values = null): array
    {
        $days = $this->effective($values);
        $i = $this->installations->current();
        $ceilings = $i->getAttribute('retention_cutoffs') ?? [];
        $out = [];
        foreach ([
            'evidence' => 'occurrence_days',
            'hourly' => 'aggregate_days',
            'activity' => 'activity_days',
            'deliveries' => 'delivery_days',
        ] as $kind => $key) {
            $floor = CarbonImmutable::now('UTC')->subDays($days[$key]);
            if (isset($ceilings[$kind])) {
                $floor = $floor->max(CarbonImmutable::parse($ceilings[$kind], 'UTC'));
            }$out[$kind] = $floor->format('Y-m-d H:i:s.u');
        }

        return $out + ['five_minute' => CarbonImmutable::now('UTC')->subHours(48)->format('Y-m-d H:i:s.u')];
    }

    // Called under the exclusive installation fence before any saved policy change.
    public function remember(Installation $i, array $old, array $new): void
    {
        if (! $this->ready()) {
            return;
        }$cutoffs = $i->getAttribute('retention_cutoffs') ?? [];
        foreach ([
            'evidence' => 'occurrence_days',
            'hourly' => 'aggregate_days',
            'activity' => 'activity_days',
            'deliveries' => 'delivery_days',
        ] as $kind => $key) {
            $at = CarbonImmutable::now('UTC')->subDays(min($old[$key], $new[$key]));
            if (isset($cutoffs[$kind])) {
                $at = $at->max(CarbonImmutable::parse($cutoffs[$kind], 'UTC'));
            }$cutoffs[$kind] = $at->toISOString();
        }
        $i->setAttribute('retention_cutoffs', $cutoffs);
        $i->save();
    }

    public function evidence($q, string $alias = 'telescope_monitor_occurrence_evidence')
    {
        $floor = $this->floors()['evidence'];

        return $q->where($alias.'.expires_at', '>', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))->whereExists(fn ($sub) => $sub->selectRaw('1')->from('telescope_monitor_occurrences as retention_o')->whereColumn('retention_o.id', $alias.'.occurrence_id')->where('retention_o.occurred_at', '>', $floor));
    }

    public function metrics($q, string $alias = 'telescope_monitor_metric_buckets')
    {
        $floors = $this->floors();

        return $q->where($alias.'.expires_at', '>', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))->where(fn ($q) => $q->where(fn ($q) => $q->where($alias.'.resolution', '5m')->where($alias.'.bucket_start_at', '>=', $floors['five_minute']))->orWhere(fn ($q) => $q->where($alias.'.resolution', '1h')->where($alias.'.bucket_start_at', '>=', $floors['hourly'])));
    }
}
