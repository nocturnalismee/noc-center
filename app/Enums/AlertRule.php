<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum AlertRule: string
{
    case ServerOffline = 'server_offline';
    case ServerStale = 'server_stale';
    case CpuHigh = 'cpu_high';
    case MemoryHigh = 'memory_high';
    case DiskHigh = 'disk_high';
    case InodeHigh = 'inode_high';
    case LoadHigh = 'load_high';
    case SmartFailed = 'smart_failed';
    case SmartWarning = 'smart_warning';
    case ServiceDown = 'service_down';
    case MysqlUnreachable = 'mysql_unreachable';
    case QuotaOver = 'quota_over';
    case ModuleCacheStale = 'module_cache_stale';
    case SecurityDisabled = 'security_disabled';

    /**
     * Sentence label used on the server detail and edit screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::ServerOffline => 'Server offline',
            self::ServerStale => 'Data is stale',
            self::CpuHigh => 'CPU high',
            self::MemoryHigh => 'Memory high',
            self::DiskHigh => 'Disk almost full',
            self::InodeHigh => 'Inodes high',
            self::LoadHigh => 'Load high',
            self::SmartFailed => 'SMART failed',
            self::SmartWarning => 'SMART warning',
            self::ServiceDown => 'Service down',
            self::MysqlUnreachable => 'MySQL unreachable',
            self::QuotaOver => 'Quota exceeded',
            self::ModuleCacheStale => 'Module cache stale',
            self::SecurityDisabled => 'Security layer disabled',
        };
    }

    /**
     * Compact label used in the fleet table's issue pills.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::ServerOffline => 'OFFLINE',
            self::ServerStale => 'STALE',
            self::CpuHigh => 'CPU',
            self::MemoryHigh => 'MEM',
            self::DiskHigh => 'DISK',
            self::InodeHigh => 'INODE',
            self::LoadHigh => 'LOAD',
            self::SmartFailed => 'SMART',
            self::SmartWarning => 'SMART W',
            self::ServiceDown => 'SERVICE',
            self::MysqlUnreachable => 'MYSQL',
            self::QuotaOver => 'QUOTA',
            self::ModuleCacheStale => 'STALE',
            self::SecurityDisabled => 'SECURITY',
        };
    }

    /**
     * Title stored on the alert row and used by the engine's messages.
     */
    public function alertTitle(): string
    {
        return match ($this) {
            self::ServerOffline => 'Server offline',
            self::ServerStale => 'Server stale',
            self::CpuHigh => 'CPU high',
            self::MemoryHigh => 'Memory high',
            self::DiskHigh => 'Disk usage high',
            self::InodeHigh => 'Inode usage high',
            self::LoadHigh => 'Load average high',
            self::SmartFailed => 'SMART health failed',
            self::SmartWarning => 'SMART warning',
            self::ServiceDown => 'Service down',
            self::MysqlUnreachable => 'MySQL unreachable',
            self::QuotaOver => 'Disk quota exceeded',
            self::ModuleCacheStale => 'Module data stale',
            self::SecurityDisabled => 'Security disabled',
        };
    }

    /**
     * Heading used for the rule on the alert settings screen.
     */
    public function settingsLabel(): string
    {
        return match ($this) {
            self::ServerOffline => 'Server Offline',
            self::ServerStale => 'Server Stale',
            self::CpuHigh => 'CPU High',
            self::MemoryHigh => 'Memory High',
            self::DiskHigh => 'Disk High',
            self::InodeHigh => 'Inode High',
            self::LoadHigh => 'Load High',
            self::SmartFailed => 'SMART Failed',
            self::SmartWarning => 'SMART Warning',
            self::ServiceDown => 'Service Down',
            self::MysqlUnreachable => 'MySQL Unreachable',
            self::QuotaOver => 'Quota Exceeded',
            self::ModuleCacheStale => 'Module Cache Stale',
            self::SecurityDisabled => 'Security Disabled',
        };
    }

    /**
     * Labels for a list of stored rule codes, preserving their order and
     * dropping duplicates. Unknown codes are humanised.
     *
     * @param  list<mixed>  $codes
     * @return list<string>
     */
    public static function labels(array $codes, bool $short = false): array
    {
        $labels = [];

        foreach ($codes as $code) {
            if (! is_string($code) || $code === '') {
                continue;
            }

            $rule = self::tryFrom($code);
            $labels[] = match (true) {
                $rule !== null && $short => $rule->shortLabel(),
                $rule !== null => $rule->label(),
                $short => strtoupper(str_replace('_', ' ', $code)),
                default => Str::headline(str_replace('_', ' ', $code)),
            };
        }

        return array_values(array_unique($labels));
    }
}
