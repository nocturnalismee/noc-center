<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chart ranges
    |--------------------------------------------------------------------------
    |
    | Each range names its source table. `raw` ranges read `metric_samples`
    | at the server's own interval; `rollup` ranges read `metric_rollups` at
    | the configured resolution. `cap` is the maximum number of points the
    | endpoint returns; beyond it, server-side buckets are widened by an
    | integer factor. Adding a range here is enough to expose it.
    |
    */

    'ranges' => [
        '1h' => ['seconds' => 3600, 'source' => 'raw', 'resolution' => null, 'cap' => 240],
        '6h' => ['seconds' => 21600, 'source' => 'raw', 'resolution' => null, 'cap' => 240],
        '24h' => ['seconds' => 86400, 'source' => 'raw', 'resolution' => null, 'cap' => 240],
        '7d' => ['seconds' => 604800, 'source' => 'rollup', 'resolution' => 300, 'cap' => 1000],
        '30d' => ['seconds' => 2592000, 'source' => 'rollup', 'resolution' => 3600, 'cap' => 1000],
        '90d' => ['seconds' => 7776000, 'source' => 'rollup', 'resolution' => 3600, 'cap' => 1000],
        '1y' => ['seconds' => 31536000, 'source' => 'rollup', 'resolution' => 86400, 'cap' => 1000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chart metrics
    |--------------------------------------------------------------------------
    |
    | `raw` selects the `metric_samples` column and `rollup` the
    | `metric_rollups` sum column. The series repository divides the sum by
    | the bucket's sample count, never by another average.
    |
    */

    'metrics' => [
        'cpu' => ['raw' => 'cpu_usage_pct', 'rollup' => 'cpu_usage_sum'],
        'iowait' => ['raw' => 'cpu_iowait_pct', 'rollup' => 'cpu_iowait_sum'],
        'memory' => ['raw' => 'mem_used_pct', 'rollup' => 'mem_used_pct_sum'],
        'memory_bytes' => [
            'raw' => 'mem_used_bytes',
            'rollup' => 'mem_used_bytes_sum',
            'fallback_rollup' => 'mem_used_pct_sum',
            'total' => 'mem_total_bytes',
        ],
        'swap' => ['raw' => 'swap_used_pct', 'rollup' => 'swap_used_pct_sum'],
        'disk' => ['raw' => 'disk_used_pct', 'rollup' => 'disk_used_pct_sum'],
        'disk_bytes' => [
            'raw' => 'disk_used_bytes',
            'rollup' => 'disk_used_bytes_sum',
            'fallback_rollup' => 'disk_used_pct_sum',
            'total' => 'disk_total_bytes',
        ],
        'inode' => ['raw' => 'disk_inode_pct', 'rollup' => 'disk_inode_pct_sum'],
        'load' => ['raw' => 'load1', 'rollup' => 'load1_sum'],
        'rx' => ['raw' => 'net_rx_bps', 'rollup' => 'net_rx_bps_sum'],
        'tx' => ['raw' => 'net_tx_bps', 'rollup' => 'net_tx_bps_sum'],
        'processes' => ['raw' => 'processes_running', 'rollup' => 'processes_running_sum'],
    ],

];
