<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alert Defaults
    |--------------------------------------------------------------------------
    |
    | These values are the built-in defaults for alert rules and delivery
    | channels. The settings repository overrides them with rows from the
    | settings table when an administrator changes them in the dashboard.
    |
    */

    'reminder_hours' => 6,

    'retry_backoff_seconds' => [30, 120, 300],

    'test_rate_per_minute' => 3,

    /*
    | How long a queued `SendAlertNotification` job stays "unique" for one
    | `(alert, channel, event)`, in seconds. A duplicate queued inside this
    | window is dropped; a failed job is retried through `retry_backoff_seconds`
    | because the lock is released when the job starts running.
    */
    'notification_unique_for' => 300,

    /*
    | Base URL used in alert notifications. Every notification links to the
    | alert detail page so an operator can act on it.
    */
    'url' => env('APP_URL', 'http://localhost'),

    /*
    | Connection timeout (seconds) and total timeout (seconds) for the fixed
    | Telegram host. The bot token lives in the URL path, so the full URL must
    | never reach a log, exception message, audit row or the UI.
    */
    'telegram_connect_timeout' => 5,
    'telegram_timeout' => 10,

    'channels' => [

        'email' => [
            'enabled' => true,
        ],

        'telegram' => [
            'enabled' => false,
            'bot_token' => null,
            'chat_id' => null,
            'message_thread_id' => null,
        ],

    ],

    'rules' => [

        'server_offline' => [
            'enabled' => true,
        ],

        'server_stale' => [
            'enabled' => true,
        ],

        'cpu_high' => [
            'enabled' => true,
            'warning' => 90,
            'critical' => 95,
            'consecutive_samples' => 3,
        ],

        'memory_high' => [
            'enabled' => true,
            'warning' => 90,
            'critical' => 95,
        ],

        'disk_high' => [
            'enabled' => true,
            'warning' => 85,
            'critical' => 95,
        ],

        'inode_high' => [
            'enabled' => true,
            'warning' => 85,
            'critical' => 95,
        ],

        'load_high' => [
            'enabled' => true,
            'warning_multiplier' => 1.0,
            'critical_multiplier' => 2.0,
            'consecutive_samples' => 3,
        ],

        'smart_failed' => [
            'enabled' => true,
        ],

        'smart_warning' => [
            'enabled' => true,
        ],

        'service_down' => [
            'enabled' => true,
        ],

        'mysql_unreachable' => [
            'enabled' => true,
        ],

        'quota_over' => [
            'enabled' => true,
        ],

        'module_cache_stale' => [
            'enabled' => true,
            'smart_days' => 45,
            'diskquota_hours' => 48,
            'imunify_minutes' => 30,
            'mysql_minutes' => 3,
        ],

        'security_disabled' => [
            'enabled' => false,
        ],

    ],

];
