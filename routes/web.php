<?php

use Illuminate\Support\Facades\Route;
use TelescopeMonitor\Http\Controllers\AlertController;
use TelescopeMonitor\Http\Controllers\ApiController;
use TelescopeMonitor\Http\Controllers\AssetController;
use TelescopeMonitor\Http\Controllers\CaptureController;
use TelescopeMonitor\Http\Controllers\CollaborationController;
use TelescopeMonitor\Http\Controllers\DashboardController;
use TelescopeMonitor\Http\Controllers\IntegrationController;
use TelescopeMonitor\Http\Controllers\InvestigationController;
use TelescopeMonitor\Http\Controllers\OperationsController;
use TelescopeMonitor\Http\Middleware\Authorize;
use TelescopeMonitor\Http\Middleware\MaintenanceGuard;
use TelescopeMonitor\Http\Middleware\RequireStorage;

Route::prefix(config('telescope-monitor.path'))->name('telescope-monitor.')
    ->middleware(array_merge(config('telescope-monitor.middleware'), [Authorize::class]))
    ->group(function () {
        Route::get('/assets/{build}/{file}', AssetController::class)->where('file', '.*')->name('asset');
        Route::prefix('api/v1')->name('api.')->middleware(MaintenanceGuard::class)->group(function () {
            Route::middleware(RequireStorage::class)->group(function () {
                $controller = CollaborationController::class;
                Route::get('/views', [$controller, 'views']);
                Route::get('/views/{view}', [$controller, 'view'])->whereUuid('view');
                Route::get('/view-preferences', [$controller, 'preferences']);
                Route::get('/assignment-directory', [$controller, 'directory']);
                Route::get('/issues/{issue}/notes', [$controller, 'notes'])->whereUuid('issue');
                Route::post('/issues/{issue}/notes', [$controller, 'command'])->whereUuid('issue')->defaults('collaboration_operation', 'note.create');
                Route::patch('/issues/{issue}/notes/{note}', [$controller, 'command'])->whereUuid('issue')->whereUuid('note')->defaults('collaboration_operation', 'note.update');
                Route::delete('/issues/{issue}/notes/{note}', [$controller, 'command'])->whereUuid('issue')->whereUuid('note')->defaults('collaboration_operation', 'note.delete');
                Route::post('/views', [$controller, 'command'])->defaults('collaboration_operation', 'view.create');
                Route::patch('/views/{view}', [$controller, 'command'])->whereUuid('view')->defaults('collaboration_operation', 'view.update');
                Route::patch('/views/{view}/name', [$controller, 'command'])->whereUuid('view')->defaults('collaboration_operation', 'view.rename');
                Route::delete('/views/{view}', [$controller, 'command'])->whereUuid('view')->defaults('collaboration_operation', 'view.delete');
                Route::put('/view-preferences', [$controller, 'command'])->defaults('collaboration_operation', 'preferences');
                Route::post('/issues/{issue}/assignment', [$controller, 'command'])->whereUuid('issue')->defaults('collaboration_operation', 'assignment');
            });
            Route::middleware(RequireStorage::class)->group(function () {
                $alerts = AlertController::class;
                $integrations = IntegrationController::class;
                Route::get('/integrations', [$integrations, 'configuration']);
                Route::get('/issues/{issue}/github', [$integrations, 'links'])->whereUuid('issue');
                Route::middleware(Authorize::class.':operate')->group(function () use ($integrations) {
                    Route::post('/issues/{issue}/github/preview', [$integrations, 'preview'])->whereUuid('issue');
                    foreach (['create', 'recreate', 'link', 'unlink', 'refresh', 'inspect'] as $action) {
                        Route::post('/issues/{issue}/github/'.$action, [$integrations, 'command'])->whereUuid('issue')->defaults('integration_operation', 'github.'.$action);
                    }
                });
                Route::middleware(Authorize::class.':manage')->group(function () use ($integrations) {
                    Route::post('/integrations', [$integrations, 'command'])->defaults('integration_operation', 'connection.create');
                    Route::patch('/integrations/{connection}', [$integrations, 'command'])->whereUuid('connection')->defaults('integration_operation', 'connection.update');
                    Route::delete('/integrations/{connection}', [$integrations, 'command'])->whereUuid('connection')->defaults('integration_operation', 'connection.disconnect');
                    foreach (['test', 'secret.replace', 'key.stage', 'key.activate'] as $action) {
                        Route::post('/integrations/{connection}/'.$action, [$integrations, 'command'])->whereUuid('connection')->defaults('integration_operation', $action);
                    }
                });
                Route::get('/deliveries', [$alerts, 'history']);
                Route::get('/deliveries/{delivery}', [$alerts, 'detail'])->whereUuid('delivery');
                Route::post('/deliveries/{delivery}/retry', [$alerts, 'command'])->whereUuid('delivery')->middleware(Authorize::class.':operate')->defaults('alert_operation', 'retry');
                Route::post('/deliveries/{delivery}/review', [$alerts, 'review'])->whereUuid('delivery')->middleware(Authorize::class.':operate');
                Route::post('/deliveries/{delivery}/resend', [$alerts, 'command'])->whereUuid('delivery')->middleware(Authorize::class.':operate')->defaults('alert_operation', 'resend');
                Route::middleware(Authorize::class.':manage')->group(function () use ($alerts) {
                    Route::get('/alerting', [$alerts, 'configuration']);
                    Route::post('/alerting/preview', [$alerts, 'preview']);
                    Route::post('/alerting/rules', [$alerts, 'command'])->defaults('alert_operation', 'rule.create');
                    Route::patch('/alerting/rules/{rule}', [$alerts, 'command'])->whereUuid('rule')->defaults('alert_operation', 'rule.update');
                    Route::delete('/alerting/rules/{rule}', [$alerts, 'command'])->whereUuid('rule')->defaults('alert_operation', 'rule.delete');
                    Route::post('/alerting/email', [$alerts, 'command'])->defaults('alert_operation', 'connection.create');
                    Route::patch('/alerting/email/{connection}', [$alerts, 'command'])->whereUuid('connection')->defaults('alert_operation', 'connection.update');
                    Route::delete('/alerting/email/{connection}', [$alerts, 'command'])->whereUuid('connection')->defaults('alert_operation', 'connection.disconnect');
                    Route::post('/alerting/tests', [$alerts, 'command'])->defaults('alert_operation', 'test');
                    Route::get('/alerting/tests/{operation}', [$alerts, 'operation'])->whereUuid('operation');
                });
            });
            Route::get('/overview', [InvestigationController::class, 'overview'])->middleware(RequireStorage::class);
            Route::get('/issues', [InvestigationController::class, 'inbox'])->middleware(RequireStorage::class);
            Route::get('/issues/{issue}', [InvestigationController::class, 'detail'])->whereUuid('issue')->middleware(RequireStorage::class);
            Route::post('/issues/{issue}/actions', [InvestigationController::class, 'act'])->whereUuid('issue')->middleware([RequireStorage::class, Authorize::class.':operate']);
            Route::get('/bootstrap', [ApiController::class, 'bootstrap'])->name('bootstrap');
            Route::get('/capture/occurrences/{occurrence}', [CaptureController::class, 'evidence'])->whereUuid('occurrence')->middleware(RequireStorage::class);
            Route::middleware(Authorize::class.':manage')->group(function () {
                Route::get('/settings/{group}', [ApiController::class, 'settings'])->whereIn('group', ['general', 'privacy', 'retention'])->middleware(RequireStorage::class);
                Route::patch('/settings/{group}', [ApiController::class, 'update'])->whereIn('group', ['general', 'privacy', 'retention'])->middleware(RequireStorage::class);
                Route::post('/privacy/preview', [ApiController::class, 'redaction'])->middleware(RequireStorage::class);
                Route::post('/capture/probes', [CaptureController::class, 'probe'])->middleware(RequireStorage::class);
                Route::get('/capture/probes/{operation}', [CaptureController::class, 'status'])->whereUuid('operation')->middleware(RequireStorage::class);
                Route::get('/operations', [OperationsController::class, 'read'])->middleware(RequireStorage::class);
                Route::get('/setup/status', [OperationsController::class, 'setup'])->middleware(RequireStorage::class);
                Route::post('/setup/finish', [OperationsController::class, 'finish'])->middleware(RequireStorage::class);
                Route::post('/retention/preview', [OperationsController::class, 'preview'])->middleware(RequireStorage::class);
                Route::get('/health', [ApiController::class, 'health']);
                Route::post('/health/check', [ApiController::class, 'check']);
                Route::patch('/setup', [ApiController::class, 'setup'])->middleware(RequireStorage::class);
            });
            Route::any('/{missing?}', [ApiController::class, 'notFound'])->where('missing', '.*');
        });
        Route::get('/{page?}', DashboardController::class)
            ->where('page', '(?:setup|overview|errors(?:/[0-9a-fA-F-]{36})?|alerts(?:/history(?:/[0-9a-fA-F-]{36})?)?|integrations|settings(?:/(?:general|privacy|retention|access|health|email|operations))?)')->name('dashboard');
    });
