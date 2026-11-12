<?php

namespace Workbench\App\Providers;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;
use TelescopeMonitor\Auth\ActorResolver;
use TelescopeMonitor\Capture\CaptureOperations;
use TelescopeMonitor\Data\Actor;
use TelescopeMonitor\Integrations\DnsResolver;
use TelescopeMonitor\Integrations\HttpTransport;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $operationalPreview = (bool) env('EXCEPTION_MONITOR_WORKBENCH_OPERATIONS', false);
        $controlled = $operationalPreview || (bool) env('EXCEPTION_MONITOR_WORKBENCH_INTEGRATIONS', false);
        $this->app['config']->set('app.name', $controlled ? ($operationalPreview ? 'Controlled operations preview' : 'Controlled integrations preview') : 'Acme Store');
        $this->app['config']->set('app.url', 'http://127.0.0.1:8195');
        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite.database', $controlled ? ($operationalPreview ? '/private/tmp/telescope-monitor-phase7-preview.sqlite' : '/private/tmp/telescope-monitor-phase6-preview.sqlite') : dirname(__DIR__, 2).'/database/database.sqlite');
        $this->app['config']->set('exception-monitor.workbench_controlled_integrations', $controlled);
        if ($controlled) {
            $this->app->bind(HttpTransport::class, WorkbenchIntegrationTransport::class);
            $this->app->bind(DnsResolver::class, WorkbenchIntegrationTransport::class);
            $this->app['config']->set('app.url', $operationalPreview ? 'http://127.0.0.1:8198' : 'http://127.0.0.1:8197');
        }
        $this->app['config']->set('telescope.storage.database.connection', 'sqlite');
        $this->app['config']->set('exception-monitor.middleware', ['web']);
        $this->app['config']->set('mail.default', 'array');
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('exception-monitor.assignments', [
            'enabled' => (bool) env('EXCEPTION_MONITOR_WORKBENCH_ASSIGNMENTS', false),
            'directory' => WorkbenchAssignmentDirectory::class,
        ]);
        $this->app['config']->set('session.driver', 'file');
        $this->app['config']->set('session.files', dirname(__DIR__, 2).'/storage/framework/sessions');
        $this->app->bind(ActorResolver::class, fn () => new class implements ActorResolver
        {
            public function current(): ?Actor
            {
                return app()->environment('local', 'workbench') ? new Actor('workbench:developer', 'Alex Developer') : null;
            }

            public function resolve(Actor $actor): ?Authenticatable
            {
                return $this->current()?->key === $actor->key ? new GenericUser([
                    'id' => 'developer',
                    'name' => 'Alex Developer',
                ]) : null;
            }
        });
    }

    public function boot(): void
    {
        if (config('exception-monitor.workbench_controlled_integrations')) {
            if (! ($this->app->make(HttpTransport::class) instanceof WorkbenchIntegrationTransport)) {
                throw new \LogicException('Controlled preview transport must be active.');
            }
            Artisan::command('workbench:integration-demo', function () {
                Telescope::startRecording(false);
                $exception = new \RuntimeException('Payment callback failed in the controlled preview');
                Telescope::recordException(IncomingExceptionEntry::make($exception, [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => base_path('app/Services/Payment.php'),
                    'line' => 58,
                    'trace' => [],
                ]));
                Telescope::store(app(EntriesRepository::class));
                app(CaptureOperations::class)->import(100);
                app(CaptureOperations::class)->process(100);
                $id = DB::table('exception_monitor_issues')->where('is_test', false)->latest('id')->value('public_id');
                $this->line(rtrim(config('app.url'), '/').'/exception-monitor/errors/'.$id.'?tab=links');
            });
            Artisan::command('workbench:integration-mode', function () {
                $this->info('Controlled transports: external I/O disabled.');
            });
        }
        foreach (['viewExceptionMonitor', 'operateExceptionMonitor', 'manageExceptionMonitor'] as $gate) {
            Gate::define($gate, fn ($user) => $this->app->environment('local', 'workbench'));
        }
    }
}
