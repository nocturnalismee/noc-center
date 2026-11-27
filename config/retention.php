<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Age-based retention windows, expressed in days. The pruning command
    | deletes rows in batches and never touches open alerts, active tokens,
    | or users.
    |
    */

    'metric_samples_days' => 14,

    'metric_details_days' => 7,

    'rollup_days' => [
        '5m' => 30,
        '1h' => 365,
        '1d' => 730,
    ],

    'resolved_alerts_days' => 365,

    'alert_deliveries_days' => 90,

    'audit_events_days' => 365,

    'failed_jobs_days' => 30,

    'revoked_tokens_days' => 90,

    // Soft-deleted servers are retained this many days before automatic purge.
    // 0 disables automatic purge; manual purge remains available.
    'deleted_server_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Pruning batches
    |--------------------------------------------------------------------------
    |
    | Each table is pruned in small batches so SQLite never holds a long
    | write lock. 5,000 rows per batch and at most 50 batches per table per
    | run keeps a single prune pass bounded.
    |
    */

    'batch_size' => 5000,

    'max_batches' => 50,

];
