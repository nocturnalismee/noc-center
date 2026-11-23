<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricRollup extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'resolution_seconds',
        'bucket_start',
        'samples',
        'cpu_usage_sum',
        'cpu_iowait_sum',
        'mem_used_pct_sum',
        'disk_used_pct_sum',
        'swap_used_pct_sum',
        'disk_inode_pct_sum',
        'load1_sum',
        'net_rx_bps_sum',
        'net_tx_bps_sum',
        'processes_running_sum',
        'processes_blocked_sum',
        'cpu_usage_min',
        'cpu_usage_max',
        'mem_used_pct_min',
        'mem_used_pct_max',
        'disk_used_pct_min',
        'disk_used_pct_max',
        'load1_max',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolution_seconds' => 'integer',
            'bucket_start' => 'datetime',
            'samples' => 'integer',
            'cpu_usage_sum' => 'float',
            'cpu_iowait_sum' => 'float',
            'mem_used_pct_sum' => 'float',
            'disk_used_pct_sum' => 'float',
            'swap_used_pct_sum' => 'float',
            'disk_inode_pct_sum' => 'float',
            'load1_sum' => 'float',
            'net_rx_bps_sum' => 'integer',
            'net_tx_bps_sum' => 'integer',
            'processes_running_sum' => 'integer',
            'processes_blocked_sum' => 'integer',
            'cpu_usage_min' => 'float',
            'cpu_usage_max' => 'float',
            'mem_used_pct_min' => 'float',
            'mem_used_pct_max' => 'float',
            'disk_used_pct_min' => 'float',
            'disk_used_pct_max' => 'float',
            'load1_max' => 'float',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
