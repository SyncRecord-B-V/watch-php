<?php

declare(strict_types=1);

/*
 * Watch's settings for Laravel. Read through env() so they also work after `php artisan config:cache`
 * (Laravel then no longer loads .env). Anything left null falls back to the real environment and
 * the defaults; see the README for all settings. Publish to change: php artisan vendor:publish --tag=watch-config
 */
return [
    'url' => env('WATCH_URL'),
    'api_key' => env('WATCH_API_KEY'),
    'enabled' => env('WATCH_ENABLED'),
    'environment' => env('WATCH_ENV', env('WATCH_ENVIRONMENT', env('APP_ENV'))),
    'host' => env('WATCH_HOST'),
    'app_version' => env('WATCH_APP_VERSION'),
    'app_name' => env('WATCH_APP_NAME'),
    'allow_http' => env('WATCH_ALLOW_HTTP'),
    'send_logs' => env('WATCH_SEND_LOGS'),
    'send_errors' => env('WATCH_SEND_ERRORS'),
    'performance' => env('WATCH_PERFORMANCE'),
    'min_level' => env('WATCH_MIN_LEVEL'),
    'root' => env('WATCH_ROOT'),
    'spool_dir' => env('WATCH_SPOOL_DIR'),
    'filter_keys' => env('WATCH_FILTER_KEYS'),
    'log_requests' => env('WATCH_LOG_REQUESTS'),
    'report_dependencies' => env('WATCH_REPORT_DEPENDENCIES'),
    'worker_mode' => env('WATCH_WORKER_MODE'),
    'finish_request' => env('WATCH_FINISH_REQUEST'),
    'warnings_as_errors' => env('WATCH_WARNINGS_AS_ERRORS'),
    'capture_errors' => env('WATCH_CAPTURE_ERRORS'),
    'timeout' => env('WATCH_TIMEOUT'),
    'connect_timeout' => env('WATCH_CONNECT_TIMEOUT'),
    'batch_size' => env('WATCH_BATCH_SIZE'),
    'flush_interval' => env('WATCH_FLUSH_INTERVAL'),
    'diagnostics' => env('WATCH_DIAGNOSTICS'),
];
