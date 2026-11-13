<?php

return [
    'enabled' => env('TELESCOPE_MONITOR_ENABLED', true),
    // Null inherits Telescope's storage connection, then Laravel's default.
    'database' => ['connection' => env('TELESCOPE_MONITOR_DB_CONNECTION')],
    'path' => env('TELESCOPE_MONITOR_PATH', 'telescope-monitor'),
    'middleware' => ['web'],
    'authorization' => [
        'driver' => 'telescope',
        // Use 'gates' for separate view/operate/manage gates.
    ],
    'guard' => null,
    'capture' => [
        'enabled' => true,
        // Deployment-only signing: null uses APP_KEY with domain separation.
        'signing_key' => env('TELESCOPE_MONITOR_CAPTURE_KEY'),
        'signing_previous_keys' => [],
        'bridge_jobs' => true,
        'sql_templates' => false,
        'sql_max_bytes' => 2048,
        'grouping_version' => 1,
        'grouping_callback' => null,
        'inline_limit' => 100,
        'max_source_bytes' => 2097152,
        'source_timezone' => null,
        'release' => env('TELESCOPE_MONITOR_RELEASE'),
        'source_link_template' => null,
        'allowed_tags' => [],
    ],
    // Replay modes are deployment-managed; exact route/command policies override the global mode.
    'reproduce' => [
        'enabled' => true,
        'default_mode' => env('TELESCOPE_MONITOR_REPRODUCE_MODE', 'redacted'),
        'fields' => [],
        'max_bytes' => 8192,
        'sensitive_fields' => [],
        'routes' => [],
        'commands' => [],
    ],
    'capabilities' => [
        'view' => 'viewTelescopeMonitor',
        'operate' => 'operateTelescopeMonitor',
        'manage' => 'manageTelescopeMonitor',
    ],
    'alerts' => [
        'enabled' => true,
        'allowed_environments' => ['production'],
        'mailer' => null,
        'email' => [
            'include_context' => true,
            'include_stack_trace' => true,
            'max_frames' => 8,
            'allowed_tags' => [],
        ],
        'max_cooldown_seconds' => 86400,
        'max_threshold_count' => 100000,
        'pending_seconds' => 86400,
    ],
    'integrations' => [
        'credentials' => [],
        'webhooks' => ['ports' => [443], 'private_targets' => []],
        'github' => ['include_context' => true, 'include_stack_trace' => true, 'max_frames' => 8, 'allowed_tags' => []],
    ],
    'operations' => ['freshness_seconds' => 300, 'maintenance_seconds' => 3600, 'preview_limit' => 10000],
    'retention_limits' => [],
    'actor_label' => null,
    'assignments' => ['enabled' => false, 'directory' => null],
    'locked' => [],
    'queues' => [
        'connection' => null,
        'capture' => 'telescope-monitor-capture',
        'delivery' => 'telescope-monitor-deliveries',
    ],
];
