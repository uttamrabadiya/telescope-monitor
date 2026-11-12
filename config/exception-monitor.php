<?php

return [
    'enabled' => env('EXCEPTION_MONITOR_ENABLED', true),
    // Null inherits Telescope's storage connection, then Laravel's default.
    'database' => ['connection' => env('EXCEPTION_MONITOR_DB_CONNECTION')],
    'path' => env('EXCEPTION_MONITOR_PATH', 'exception-monitor'),
    'middleware' => ['web'],
    'authorization' => [
        'driver' => 'telescope', // Use 'gates' for separate view/operate/manage gates.
        'allow_guest_jobs' => false, // Deployed guest-only access needs explicit queued-work permission.
    ],
    'guard' => null,
    'capture' => [
        'enabled' => true,
        'bridge_jobs' => true,
        'sql_templates' => false,
        'sql_max_bytes' => 2048,
        'grouping_version' => 1,
        'grouping_callback' => null,
        'inline_limit' => 100,
        'max_source_bytes' => 2097152,
        'source_timezone' => null,
        'release' => env('EXCEPTION_MONITOR_RELEASE'),
        'source_link_template' => null,
        'allowed_tags' => [],
    ],
    'capabilities' => [
        'view' => 'viewExceptionMonitor',
        'operate' => 'operateExceptionMonitor',
        'manage' => 'manageExceptionMonitor',
    ],
    'alerts' => ['enabled' => true, 'allowed_environments' => ['production'], 'mailer' => null, 'max_cooldown_seconds' => 86400, 'max_threshold_count' => 100000, 'pending_seconds' => 86400],
    'integrations' => ['credentials' => [], 'webhooks' => ['ports' => [443], 'private_targets' => []]],
    'operations' => ['freshness_seconds' => 300, 'maintenance_seconds' => 3600, 'preview_limit' => 10000],
    'retention_limits' => [],
    'actor_label' => null,
    'assignments' => ['enabled' => false, 'directory' => null],
    'locked' => [],
    'queues' => [
        'connection' => null,
        'capture' => 'exception-monitor-capture',
        'delivery' => 'exception-monitor-deliveries',
    ],
];
