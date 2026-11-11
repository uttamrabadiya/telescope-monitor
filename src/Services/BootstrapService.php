<?php

namespace TelescopeMonitor\Services;

use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Auth\Capabilities;
use TelescopeMonitor\Collaboration\Assignments;
use TelescopeMonitor\Integrations\GitHubIssues;
use TelescopeMonitor\Operations\RetentionPolicy;
use TelescopeMonitor\Repositories\Contracts\AlertPlanning;
use TelescopeMonitor\Repositories\Contracts\CaptureInboxRepository;
use TelescopeMonitor\Repositories\Contracts\CollaborationRepository;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;

final class BootstrapService
{
    public function __construct(
        private InstallationRepository $installations,
        private SettingsService $settings,
        private Capabilities $capabilities,
        private ActorResolver $actors,
        private HealthService $health,
        private CaptureInboxRepository $inbox,
        private StoredExceptionAdapter $capture,
    ) {}

    public function read(): array
    {
        $storage = $this->installations->storageState();
        $ready = $storage === 'ready';
        $installation = $ready ? $this->installations->current() : null;
        $general = $ready ? $this->settings->group('general')['values'] : $this->settings->defaults('general');
        $manage = $this->capabilities->allows('manage');

        return [
            'application' => $general,
            'environment' => app()->environment(),
            'timezones' => $manage ? \DateTimeZone::listIdentifiers() : [],
            'actor' => [
                'label' => $this->actors->current()?->label,
            ],
            'capabilities' => $this->capabilities->current(),
            'installation' => $installation ? [
                'id' => $installation->public_id,
                'state' => $installation->state, 'generation' => $installation->generation,
                'setup' => $installation->setup_state ?? [],
                'config_revision' => $installation->config_revision,
            ] : null,
            'storage' => $storage,
            'capture_status' => $ready && $this->inbox->ready() ? array_merge($this->inbox->status(), ['source_enabled' => $this->capture->enabled()]) : null,
            'features' => [
                'settings' => $ready,
                'operations' => $ready && app(RetentionPolicy::class)->ready(),
                'setup' => true,
                'capture' => $ready && $this->inbox->ready(),
                'errors' => $ready && $this->inbox->ready(),
                'alerts' => $ready && app(AlertPlanning::class)->ready(),
                'controlled_integrations' => (bool) config('exception-monitor.workbench_controlled_integrations', false) && app()->environment('local', 'workbench'),
                'integrations' => $ready && app(GitHubIssues::class)->ready(),
                'assignments' => app(Assignments::class)->enabled() && app(CollaborationRepository::class)->ready(),
                'collaboration' => $ready && app(CollaborationRepository::class)->ready(),
            ],
            'assignment_configuration' => $manage ? app(Assignments::class)->configuration() : null,
            'health' => $manage ? $this->health->inspect() : [],
            'data_as_of' => now()->toIso8601String(),
        ];
    }
}
