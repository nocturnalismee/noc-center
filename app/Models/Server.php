<?php

namespace App\Models;

use App\Enums\ServerHealth;
use App\Enums\ServerStatus;
use App\Fleet\FleetState;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    use HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'hostname',
        'panel',
        'server_type',
        'provider',
        'location',
        'ip_address',
        'label',
        'alerts_enabled',
        'notes',
        'os_name',
        'os_version',
        'kernel',
        'agent_version',
        'agent_uuid',
        'interval_seconds',
        'last_seen_at',
        'last_sample_at',
        'stale_after',
        'offline_after',
        'cpu_usage_pct',
        'cpu_iowait_pct',
        'load1',
        'load5',
        'load15',
        'mem_used_pct',
        'swap_used_pct',
        'disk_used_pct',
        'disk_inode_pct',
        'net_rx_bps',
        'net_tx_bps',
        'processes_running',
        'processes_blocked',
        'uptime_seconds',
        'health',
        'issue_codes',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_seconds' => 'integer',
            'last_seen_at' => 'datetime',
            'last_sample_at' => 'datetime',
            'stale_after' => 'datetime',
            'offline_after' => 'datetime',
            'cpu_usage_pct' => 'float',
            'cpu_iowait_pct' => 'float',
            'load1' => 'float',
            'load5' => 'float',
            'load15' => 'float',
            'mem_used_pct' => 'float',
            'swap_used_pct' => 'float',
            'disk_used_pct' => 'float',
            'disk_inode_pct' => 'float',
            'net_rx_bps' => 'integer',
            'net_tx_bps' => 'integer',
            'processes_running' => 'integer',
            'processes_blocked' => 'integer',
            'uptime_seconds' => 'integer',
            'health' => ServerHealth::class,
            'issue_codes' => 'array',
            'is_active' => 'boolean',
            'alerts_enabled' => 'boolean',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ServerToken::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    public function rollups(): HasMany
    {
        return $this->hasMany(MetricRollup::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function latestSample(): HasOne
    {
        return $this->hasOne(MetricSample::class)->latestOfMany('collected_at');
    }

    public function status(): ServerStatus
    {
        return FleetState::status($this);
    }
}
