<?php

namespace Tests\Unit\Enums;

use App\Enums\AlertRule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertRuleTest extends TestCase
{
    /**
     * @return array<string, array{AlertRule, string, string, string, string}>
     */
    public static function labels(): array
    {
        return [
            'server_offline' => [AlertRule::ServerOffline, 'Server offline', 'OFFLINE', 'Server offline', 'Server Offline'],
            'server_stale' => [AlertRule::ServerStale, 'Data is stale', 'STALE', 'Server stale', 'Server Stale'],
            'cpu_high' => [AlertRule::CpuHigh, 'CPU high', 'CPU', 'CPU high', 'CPU High'],
            'memory_high' => [AlertRule::MemoryHigh, 'Memory high', 'MEM', 'Memory high', 'Memory High'],
            'disk_high' => [AlertRule::DiskHigh, 'Disk almost full', 'DISK', 'Disk usage high', 'Disk High'],
            'inode_high' => [AlertRule::InodeHigh, 'Inodes high', 'INODE', 'Inode usage high', 'Inode High'],
            'load_high' => [AlertRule::LoadHigh, 'Load high', 'LOAD', 'Load average high', 'Load High'],
            'smart_failed' => [AlertRule::SmartFailed, 'SMART failed', 'SMART', 'SMART health failed', 'SMART Failed'],
            'smart_warning' => [AlertRule::SmartWarning, 'SMART warning', 'SMART W', 'SMART warning', 'SMART Warning'],
            'service_down' => [AlertRule::ServiceDown, 'Service down', 'SERVICE', 'Service down', 'Service Down'],
            'mysql_unreachable' => [AlertRule::MysqlUnreachable, 'MySQL unreachable', 'MYSQL', 'MySQL unreachable', 'MySQL Unreachable'],
            'quota_over' => [AlertRule::QuotaOver, 'Quota exceeded', 'QUOTA', 'Disk quota exceeded', 'Quota Exceeded'],
            'module_cache_stale' => [AlertRule::ModuleCacheStale, 'Module cache stale', 'STALE', 'Module data stale', 'Module Cache Stale'],
            'security_disabled' => [AlertRule::SecurityDisabled, 'Security layer disabled', 'SECURITY', 'Security disabled', 'Security Disabled'],
        ];
    }

    #[DataProvider('labels')]
    public function test_label_forms_for_every_rule(
        AlertRule $rule,
        string $label,
        string $shortLabel,
        string $alertTitle,
        string $settingsLabel,
    ): void {
        $this->assertSame($label, $rule->label());
        $this->assertSame($shortLabel, $rule->shortLabel());
        $this->assertSame($alertTitle, $rule->alertTitle());
        $this->assertSame($settingsLabel, $rule->settingsLabel());
    }

    public function test_labels_preserves_order_and_deduplicates(): void
    {
        $this->assertSame(
            ['OFFLINE', 'STALE', 'CPU'],
            AlertRule::labels(['server_offline', 'server_stale', 'cpu_high', 'cpu_high'], true),
        );

        $this->assertSame(
            ['Server offline', 'CPU high'],
            AlertRule::labels(['server_offline', 'cpu_high']),
        );
    }

    public function test_labels_filters_empty_and_non_string_codes(): void
    {
        $this->assertSame(
            ['CPU high'],
            AlertRule::labels(['', null, 42, ['x'], 'cpu_high']),
        );
    }

    public function test_labels_falls_back_for_unknown_codes(): void
    {
        $this->assertSame(['Brand New Rule'], AlertRule::labels(['brand_new_rule']));
        $this->assertSame(['BRAND NEW RULE'], AlertRule::labels(['brand_new_rule'], true));
    }
}
