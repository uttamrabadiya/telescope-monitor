<?php

namespace TelescopeMonitor\Capture;

use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Repositories\Contracts\SettingRepository;

final class CapturePolicy
{
    public function __construct(private InstallationRepository $installations, private SettingRepository $settings) {}

    public function read(): array
    {
        $i = $this->installations->current();
        $privacy = array_replace([
            'retain_stack_trace' => true,
            'retain_execution_context' => true,
            'retain_related_logs' => true,
            'retain_sql_templates' => (bool) config('telescope-monitor.capture.sql_templates', false),
            'identify_users' => false,
            'redact_patterns' => [],
        ], $this->settings->find($i->id, 'privacy')->values ?? []);
        $retention = array_replace([
            'occurrence_days' => 30,
            'aggregate_days' => 90,
            'delivery_days' => 30,
            'activity_days' => 90,
        ], $this->settings->find($i->id, 'retention')->values ?? []);
        foreach (config('telescope-monitor.locked', []) as $name => $value) {
            if (str_starts_with($name, 'privacy.')) {
                $privacy[substr($name, 8)] = $value;
            }
            if (str_starts_with($name, 'retention.')) {
                $retention[substr($name, 10)] = $value;
            }
        }

        $retention = app(RetentionPolicy::class)->effective($retention);

        return [
            'privacy' => $privacy,
            'retention' => [
                'occurrence_days' => max(1, min(90, (int) $retention['occurrence_days'])),
                'delivery_days' => max(1, min(90, (int) $retention['delivery_days'])),
                'activity_days' => $retention['activity_days'],
                'aggregate_days' => max(30, min(365, (int) $retention['aggregate_days'])),
            ],
            'revision' => $i->config_revision,
        ];
    }
}
