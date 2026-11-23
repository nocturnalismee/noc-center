<?php

namespace App\Enums;

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
}
