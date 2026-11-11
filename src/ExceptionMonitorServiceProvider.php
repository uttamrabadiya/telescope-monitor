<?php

namespace TelescopeMonitor;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Alerts\NotificationProvider;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Auth\HostActorResolver;
use TelescopeMonitor\Capture\CaptureContext;
use TelescopeMonitor\Collaboration\Assignments;
use TelescopeMonitor\Collaboration\Identity;
use TelescopeMonitor\Console\Commands\DeliverCommand;
use TelescopeMonitor\Console\Commands\DiagnoseCommand;
use TelescopeMonitor\Console\Commands\ExpireSnoozesCommand;
use TelescopeMonitor\Console\Commands\HeartbeatCommand;
use TelescopeMonitor\Console\Commands\InstallCommand;
use TelescopeMonitor\Console\Commands\MigrateCommand;
use TelescopeMonitor\Console\Commands\ProcessCaptureCommand;
use TelescopeMonitor\Console\Commands\PruneCaptureCommand;
use TelescopeMonitor\Console\Commands\PruneDeliveriesCommand;
use TelescopeMonitor\Console\Commands\PurgeCommand;
use TelescopeMonitor\Console\Commands\ReconcileCaptureCommand;
use TelescopeMonitor\Console\Commands\RecoverCaptureCommand;
use TelescopeMonitor\Console\Commands\RecoverDeliveriesCommand;
use TelescopeMonitor\Console\Commands\RewrapCommand;
use TelescopeMonitor\Console\Commands\SourceBaselineCommand;
use TelescopeMonitor\Foundation\PackagePath;
use TelescopeMonitor\Integrations\CurlTransport;
use TelescopeMonitor\Integrations\DnsResolver;
use TelescopeMonitor\Integrations\HttpTransport;
use TelescopeMonitor\Integrations\NativeDnsResolver;
use TelescopeMonitor\Integrations\ProviderRouter;
use TelescopeMonitor\Repositories\Contracts;
use TelescopeMonitor\Repositories\Eloquent;
use TelescopeMonitor\Telescope\StoredExceptionAdapter;

final class ExceptionMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/exception-monitor.php', 'exception-monitor');
        $this->app->bind(ActorResolver::class, HostActorResolver::class);
        $this->app->bind(Contracts\InstallationRepository::class, Eloquent\EloquentInstallationRepository::class);
        $this->app->bind(Contracts\SettingRepository::class, Eloquent\EloquentSettingRepository::class);
        $this->app->bind(Contracts\HealthCheckRepository::class, Eloquent\EloquentHealthCheckRepository::class);
        foreach ([
            Contracts\AlertManagement::class => Eloquent\EloquentAlertManagement::class,
            Contracts\DeliveryLedger::class => Eloquent\EloquentDeliveryLedger::class,
            Contracts\AlertPlanning::class => Eloquent\EloquentAlertPlanner::class,
            Contracts\CollaborationRepository::class => Eloquent\EloquentCollaborationRepository::class,
            Contracts\InvestigationRepository::class => Eloquent\EloquentInvestigationRepository::class,
            Contracts\LifecycleRepository::class => Eloquent\EloquentLifecycleRepository::class,
            Contracts\CaptureInboxRepository::class => Eloquent\EloquentCaptureInboxRepository::class,
            Contracts\IssueRepository::class => Eloquent\EloquentIssueRepository::class,
            Contracts\OccurrenceRepository::class => Eloquent\EloquentOccurrenceRepository::class,
            Contracts\OccurrenceEvidenceRepository::class => Eloquent\EloquentOccurrenceEvidenceRepository::class,
            Contracts\EvidenceTagRepository::class => Eloquent\EloquentEvidenceTagRepository::class,
            Contracts\MetricBucketRepository::class => Eloquent\EloquentMetricBucketRepository::class,
            Contracts\ActivityRepository::class => Eloquent\EloquentActivityRepository::class,
            Contracts\AlertEventRepository::class => Eloquent\EloquentAlertEventRepository::class,
            Contracts\SecretRepository::class => Eloquent\EloquentSecretRepository::class,
            Contracts\ActionReceiptRepository::class => Eloquent\EloquentActionReceiptRepository::class,
        ] as $contract => $implementation) {
            $this->app->scoped($contract, $implementation);
        }
        $this->app->scoped(CaptureContext::class);
        $this->app->bind(NotificationProvider::class, ProviderRouter::class);
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(HttpTransport::class, CurlTransport::class);
        $this->app->scoped(Identity::class);
        $this->app->scoped(Assignments::class);
        Telescope::tag(fn ($entry) => $this->app->make(StoredExceptionAdapter::class)->tags($entry));
        Telescope::afterStoring(function (array $entries, string $batch): bool {
            try {
                return $this->app->make(StoredExceptionAdapter::class)->stored($entries, $batch);
            } catch (\Throwable) {
                return true;
            }
        });
        $this->app['events']->listen(JobProcessing::class, function ($event) {
            $context = $this->app->make(CaptureContext::class);
            $context->failedJobException = null;
            $context->failedJobOrigin = null;
            $this->app->make(CaptureContext::class)->job = [
                'kind' => 'job',
                'job_class' => $event->job->resolveName(),
            ];
        });
        $this->app['events']->listen(JobFailed::class, function ($event) {
            $context = $this->app->make(CaptureContext::class);
            $context->failedJobException = $event->exception;
            $context->failedJobOrigin = ['kind' => 'job', 'job_class' => $event->job->resolveName()];
            $this->app->make(StoredExceptionAdapter::class)->failedJob($event);
            $this->app->make(CaptureContext::class)->job = null;
        });
        $this->app['events']->listen(JobProcessed::class, fn () => $this->app->make(CaptureContext::class)->job = null);
        $this->app['events']->listen(Looping::class, function () {
            $context = $this->app->make(CaptureContext::class);
            $context->job = null;
            $context->failedJobException = null;
            $context->failedJobOrigin = null;
        });
        $this->app['events']->listen(CommandStarting::class, function ($event) {
            $context = $this->app->make(CaptureContext::class);
            $context->job = null;
            $context->command = $event->command;
        });
        $this->app['events']->listen(CommandFinished::class, function () {
            $this->app->make(CaptureContext::class)->command = null;
        });
        $this->app->booting(function () {
            $path = $this->app->make(PackagePath::class);
            if (config('exception-monitor.enabled') && $path->valid()) {
                $this->app['config']->set('telescope.ignore_paths', array_values(array_unique(array_merge(
                    config('telescope.ignore_paths', []), [$path->prefix(), $path->prefix().'/*']
                ))));
                $this->app['config']->set('telescope.ignore_commands', array_values(array_unique(array_merge(
                    config('telescope.ignore_commands', []), ['exception-monitor:install', 'exception-monitor:migrate', 'exception-monitor:diagnose', 'exception-monitor:process', 'exception-monitor:recover', 'exception-monitor:reconcile', 'exception-monitor:prune', 'exception-monitor:expire-snoozes', 'exception-monitor:deliver', 'exception-monitor:recover-deliveries', 'exception-monitor:prune-deliveries', 'exception-monitor:purge', 'exception-monitor:source-baseline', 'exception-monitor:heartbeat', 'exception-monitor:rewrap']
                ))));
                Telescope::hideRequestParameters(['exception_monitor_private']);
                Telescope::hideResponseParameters(['exception_monitor_private']);
            }
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'exception-monitor');
        $this->publishes([
            __DIR__.'/../config/exception-monitor.php' => config_path('exception-monitor.php'),
        ], 'exception-monitor-config');
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'exception-monitor-migrations');
        $this->app['events']->listen(MessageLogged::class, function ($event) {
            if (($event->context['exception'] ?? null) instanceof \Throwable) {
                $context = $this->app->make(CaptureContext::class);
                $context->job = null;
                if ($context->failedJobException === $event->context['exception']) {
                    $context->failedJobException = null;
                    $context->failedJobOrigin = null;
                }
            }
        });
        if (config('exception-monitor.enabled') && $this->app->make(PackagePath::class)->valid()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                MigrateCommand::class,
                DiagnoseCommand::class,
                ProcessCaptureCommand::class,
                RecoverCaptureCommand::class,
                ReconcileCaptureCommand::class,
                PruneCaptureCommand::class,
                DeliverCommand::class,
                RecoverDeliveriesCommand::class,
                PruneDeliveriesCommand::class,
                ExpireSnoozesCommand::class,
                PurgeCommand::class,
                SourceBaselineCommand::class,
                HeartbeatCommand::class,
                RewrapCommand::class,
            ]);
        }
    }
}
