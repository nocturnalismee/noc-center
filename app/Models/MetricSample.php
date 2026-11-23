<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MetricSample extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id',
        'collected_at',
        'received_at',
        'schema_version',
        'agent_version',
        'interval_seconds',
        'cpu_usage_pct',
        'cpu_iowait_pct',
        'cpu_cores',
        'load1',
        'load5',
        'load15',
        'mem_total_bytes',
        'mem_used_bytes',
        'mem_used_pct',
        'swap_total_bytes',
        'swap_used_bytes',
        'swap_used_pct',
        'disk_total_bytes',
        'disk_used_bytes',
        'disk_used_pct',
        'disk_inode_pct',
        'net_rx_bps',
        'net_tx_bps',
        'processes_running',
        'processes_blocked',
        'uptime_seconds',
        'payload_bytes',
        'payload_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'received_at' => 'datetime',
            'schema_version' => 'integer',
            'interval_seconds' => 'integer',
            'cpu_usage_pct' => 'float',
            'cpu_iowait_pct' => 'float',
            'cpu_cores' => 'integer',
            'load1' => 'float',
            'load5' => 'float',
            'load15' => 'float',
            'mem_total_bytes' => 'integer',
            'mem_used_bytes' => 'integer',
            'mem_used_pct' => 'float',
            'swap_total_bytes' => 'integer',
            'swap_used_bytes' => 'integer',
            'swap_used_pct' => 'float',
            'disk_total_bytes' => 'integer',
            'disk_used_bytes' => 'integer',
            'disk_used_pct' => 'float',
            'disk_inode_pct' => 'float',
            'net_rx_bps' => 'integer',
            'net_tx_bps' => 'integer',
            'processes_running' => 'integer',
            'processes_blocked' => 'integer',
            'uptime_seconds' => 'integer',
            'payload_bytes' => 'integer',
        ];
    }

    public function detail(): HasOne
    {
        return $this->hasOne(MetricDetail::class, 'sample_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
