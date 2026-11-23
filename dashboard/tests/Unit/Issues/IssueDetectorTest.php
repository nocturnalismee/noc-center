<?php

namespace Tests\Unit\Issues;

use App\Enums\AlertRule;
use App\Enums\AlertSeverity;
use App\Enums\ServerHealth;
use App\Issues\IssueDetector;
use App\Issues\IssueSet;
use App\Models\MetricSample;
use App\Models\Server;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IssueDetectorTest extends TestCase
{
    use RefreshDatabase;

    private IssueDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = $this->app->make(IssueDetector::class);

        Carbon::setTestNow('2026-11-22 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -- CPU ---------------------------------------------------------------

    public function test_consecutive_samples_comes_from_settings(): void
    {
        // CPU at 96 for two samples: enough for a 2-sample window, not for 3.
        $this->app->make(SettingsRepository::class)->set('alerts.rules.cpu_high.consecutive_samples', 2, 'int');

        $this->assertSame([AlertRule::CpuHigh->value], $this->detectHistory([96, 96])->codes);
    }

    public function test_load_consecutive_samples_comes_from_settings(): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.load_high.consecutive_samples', 2, 'int');

        $this->assertSame([AlertRule::LoadHigh->value], $this->detectHistory(loads: [9, 9], cores: 4)->codes);
    }

    public function test_detect_issues_at_most_one_settings_query_per_call_after_warm_up(): void
    {
        // Warm the settings cache, then prove detection adds no queries at all.
        $this->detect($this->sample(), [], []);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'settings')) {
                $queries++;
            }
        });

        $this->detect($this->sample(), ['service.mysql.running' => 0], ['smart.status' => 'ok']);

        $this->assertSame(0, $queries);
    }

    public function test_cpu_high_is_critical_when_the_latest_three_samples_are_all_over_the_critical_line(): void
    {
        $issues = $this->detectHistory([95, 96, 99]);

        $this->assertSame(ServerHealth::Critical, $issues->health);
        $this->assertContains(AlertRule::CpuHigh->value, $issues->codes);
        $this->assertSame([
            'value' => 99.0,
            'threshold' => 95,
            'severity' => AlertSeverity::Critical->value,
        ], $issues->details[AlertRule::CpuHigh->value]);
    }

    public function test_cpu_high_is_warning_when_every_sample_clears_the_warning_line_but_not_the_critical_one(): void
    {
        $issues = $this->detectHistory([90, 91, 94.9]);

        $this->assertSame(ServerHealth::Warning, $issues->health);
        $this->assertSame([
            'value' => 94.9,
            'threshold' => 90,
            'severity' => AlertSeverity::Warning->value,
        ], $issues->details[AlertRule::CpuHigh->value]);
    }

    public function test_cpu_high_requires_all_three_samples_over_the_line(): void
    {
        // One spike in the middle is not an incident; the averages are gone.
        $issues = $this->detectHistory([99, 99, 89.9]);

        $this->assertNotContains(AlertRule::CpuHigh->value, $issues->codes);
        $this->assertSame(ServerHealth::Ok, $issues->health);
    }

    public function test_cpu_at_the_warning_boundary_counts_as_high(): void
    {
        $this->assertContains(AlertRule::CpuHigh->value, $this->detectHistory([90, 90, 90])->codes);
        $this->assertNotContains(AlertRule::CpuHigh->value, $this->detectHistory([89.99, 89.99, 89.99])->codes);
    }

    public function test_cpu_critical_requires_all_three_over_the_critical_line(): void
    {
        // 94.9 in the newest sample downgrades the incident to a warning.
        $issues = $this->detectHistory([96, 96, 94.9]);

        $this->assertSame(AlertSeverity::Warning->value, $issues->details[AlertRule::CpuHigh->value]['severity']);
    }

    public function test_cpu_critical_wins_when_every_sample_is_over_the_critical_line(): void
    {
        $this->assertSame(AlertSeverity::Critical->value, $this->detectHistory([96, 96, 96])->details[AlertRule::CpuHigh->value]['severity']);
    }

    // -- Memory ------------------------------------------------------------

    public function test_memory_high_is_warning_over_90_and_critical_over_95(): void
    {
        $warning = $this->detect($this->sample(mem: 90), [], []);

        $this->assertSame(ServerHealth::Warning, $warning->health);
        $this->assertSame([
            'value' => 90.0,
            'threshold' => 90,
            'severity' => AlertSeverity::Warning->value,
        ], $warning->details[AlertRule::MemoryHigh->value]);

        $critical = $this->detect($this->sample(mem: 95), [], []);

        $this->assertSame(ServerHealth::Critical, $critical->health);
        $this->assertSame(AlertSeverity::Critical->value, $critical->details[AlertRule::MemoryHigh->value]['severity']);
    }

    public function test_memory_high_has_no_consecutive_sample_requirement(): void
    {
        $this->assertSame([], $this->detect($this->sample(mem: 89.9), [], [])->codes);
    }

    public function test_missing_memory_is_never_treated_as_zero(): void
    {
        // A server that never reported memory shows no reading at all; the
        // detector runs on a row where the column is null.
        $latest = $this->unsavedSample(['mem_used_pct' => null]);

        $this->assertNull($latest->mem_used_pct);
        $this->assertSame([], $this->detect($latest, [], [])->codes);
    }

    /**
     * `metric_samples` stores `load1` as decimal(8,3), so SQLite reads 8.1001
     * back as 8.101. An in-memory sample keeps the raw float, which is the only
     * way to prove the strict `>` on the critical line itself.
     */
    public function test_load_just_around_the_critical_line(): void
    {
        // decimal(8,3) keeps 8.001 distinct from 8, so the strict `>` on the
        // critical line is testable through real rows (8.1 rounds to 8.1 and
        // is not a safe fixture).
        $above = $this->detectHistory(loads: [8.001, 8.001, 8.001], cores: 4);
        $this->assertSame(AlertSeverity::Critical->value, $above->details[AlertRule::LoadHigh->value]['severity']);

        $below = $this->detectHistory(loads: [7.999, 7.999, 7.999], cores: 4);
        $this->assertSame(AlertSeverity::Warning->value, $below->details[AlertRule::LoadHigh->value]['severity']);
    }

    // -- Disk and inodes ---------------------------------------------------

    public function test_disk_high_is_warning_over_85_and_critical_over_95(): void
    {
        $warning = $this->detect($this->sample(disk: 85), [], []);

        $this->assertSame(ServerHealth::Warning, $warning->health);
        $this->assertSame([
            'value' => 85.0,
            'threshold' => 85,
            'severity' => AlertSeverity::Warning->value,
        ], $warning->details[AlertRule::DiskHigh->value]);

        $this->assertSame(AlertSeverity::Critical->value, $this->detect($this->sample(disk: 95), [], [])->details[AlertRule::DiskHigh->value]['severity']);
        $this->assertSame([], $this->detect($this->sample(disk: 84.99), [], [])->codes);
    }

    public function test_inode_high_is_warning_over_85_and_critical_over_95(): void
    {
        $warning = $this->detect($this->sample(inode: 90), [], []);

        $this->assertSame(ServerHealth::Warning, $warning->health);
        $this->assertSame(AlertRule::InodeHigh->value, $warning->codes[0]);
        $this->assertSame([
            'value' => 90.0,
            'threshold' => 85,
            'severity' => AlertSeverity::Warning->value,
        ], $warning->details[AlertRule::InodeHigh->value]);

        $this->assertSame(AlertSeverity::Critical->value, $this->detect($this->sample(inode: 95), [], [])->details[AlertRule::InodeHigh->value]['severity']);
        $this->assertSame([], $this->detect($this->sample(inode: 84.99), [], [])->codes);
    }

    // -- Load --------------------------------------------------------------

    public function test_a_short_history_is_not_enough_for_cpu_high(): void
    {
        $this->assertSame([], $this->detectHistory([99, 99])->codes);
    }

    public function test_load_high_compares_load1_against_the_core_count(): void
    {
        // 4 cores: warning above 4, critical above 8.
        $warning = $this->detectHistory(loads: [4.5, 5, 6], cores: 4);

        $this->assertSame(ServerHealth::Warning, $warning->health);
        $this->assertSame([
            'value' => 6.0,
            'threshold' => 4,
            'severity' => AlertSeverity::Warning->value,
        ], $warning->details[AlertRule::LoadHigh->value]);

        $critical = $this->detectHistory(loads: [8.5, 9, 10], cores: 4);

        $this->assertSame(AlertSeverity::Critical->value, $critical->details[AlertRule::LoadHigh->value]['severity']);
        $this->assertSame(8, $critical->details[AlertRule::LoadHigh->value]['threshold']);
    }

    public function test_load_equal_to_the_core_count_is_not_high(): void
    {
        $this->assertSame([], $this->detectHistory(loads: [4, 4, 4], cores: 4)->codes);
    }

    public function test_load_high_requires_all_three_samples(): void
    {
        $this->assertSame([], $this->detectHistory(loads: [9, 9, 3.9], cores: 4)->codes);
    }

    public function test_load_high_is_skipped_without_a_known_core_count(): void
    {
        $this->assertSame([], $this->detectHistory(loads: [9, 9, 9], cores: 0)->codes);
    }

    public function test_load_critical_requires_all_three_over_the_critical_multiplier(): void
    {
        // 8.1 is above the 4-core warning line but not above the critical line
        // of 8: the window is a warning, not a critical.
        $issues = $this->detectHistory(loads: [9, 9, 7.5], cores: 4);

        $this->assertSame(AlertSeverity::Warning->value, $issues->details[AlertRule::LoadHigh->value]['severity']);
    }

    // -- SMART -------------------------------------------------------------

    public function test_smart_failed_is_critical(): void
    {
        $issues = $this->detect($this->sample(), [], ['smart.status' => 'failed']);

        $this->assertSame(ServerHealth::Critical, $issues->health);
        $this->assertSame([
            'value' => 'failed',
            'threshold' => 'failed',
            'severity' => AlertSeverity::Critical->value,
        ], $issues->details[AlertRule::SmartFailed->value]);
    }

    public function test_smart_status_other_than_failed_is_not_failed(): void
    {
        $this->assertSame([], $this->detect($this->sample(), [], ['smart.status' => 'ok'])->codes);
        $this->assertSame([], $this->detect($this->sample(), [], [])->codes);
    }

    public function test_smart_warning_covers_the_warning_status_and_sector_defects(): void
    {
        $fromStatus = $this->detect($this->sample(), [], ['smart.status' => 'warning']);

        $this->assertSame(ServerHealth::Warning, $fromStatus->health);
        $this->assertSame(AlertSeverity::Warning->value, $fromStatus->details[AlertRule::SmartWarning->value]['severity']);

        // Exact keys emitted by agent/modules/smart.sh.
        $reallocated = $this->detect($this->sample(), [], ['smart.status' => 'ok', 'smart.disk.sda.realloc' => 4]);

        $this->assertSame(ServerHealth::Warning, $reallocated->health);
        $this->assertSame(AlertSeverity::Warning->value, $reallocated->details[AlertRule::SmartWarning->value]['severity']);

        $pending = $this->detect($this->sample(), [], ['smart.disk.nvme0n1.pending' => 1]);

        $this->assertSame(ServerHealth::Warning, $pending->health);
    }

    public function test_legacy_sector_suffixes_are_still_understood(): void
    {
        $this->assertSame([AlertRule::SmartWarning->value], $this->detect($this->sample(), [], ['smart.disk.sda.reallocated_sectors' => 4])->codes);
        $this->assertSame([AlertRule::SmartWarning->value], $this->detect($this->sample(), [], ['smart.disk.sda.pending_sectors' => 1])->codes);
    }

    public function test_non_sector_smart_counters_never_trigger(): void
    {
        $this->assertSame([], $this->detect($this->sample(), [], [
            'smart.status' => 'ok',
            'smart.disk.sda.power_on_hours' => 40_000,
            'smart.disk.sda.written_gb' => 90_000.0,
        ])->codes);
    }

    public function test_healthy_smart_counters_do_not_trigger(): void
    {
        // The full shape the agent reports for one healthy disk.
        $this->assertSame([], $this->detect($this->sample(), [], [
            'smart.status' => 'ok',
            'smart.disk.sda.health' => 'PASSED',
            'smart.disk.sda.realloc' => 0,
            'smart.disk.sda.pending' => 0,
            'smart.disk.sda.power_on_hours' => 12_345,
            'smart.disk.sda.written_gb' => 4321.5,
        ])->codes);
    }

    public function test_a_failed_smart_status_wins_over_a_warning(): void
    {
        $issues = $this->detect($this->sample(), [], ['smart.status' => 'failed', 'smart.disk.sda.pending' => 8]);

        $this->assertContains(AlertRule::SmartFailed->value, $issues->codes);
        $this->assertNotContains(AlertRule::SmartWarning->value, $issues->codes);
    }

    // -- Services ----------------------------------------------------------

    public function test_service_down_triggers_for_installed_enabled_and_stopped_services(): void
    {
        $issues = $this->detect($this->sample(), [
            'service.httpd.installed' => 1,
            'service.httpd.enabled' => 1,
            'service.httpd.running' => 0,
        ], []);

        $this->assertSame(ServerHealth::Critical, $issues->health);
        $this->assertSame([
            'value' => ['httpd'],
            'threshold' => 'installed && enabled && running = 0',
            'severity' => AlertSeverity::Critical->value,
        ], $issues->details[AlertRule::ServiceDown->value]);
    }

    public function test_a_running_service_does_not_trigger(): void
    {
        $this->assertSame([], $this->detect($this->sample(), [
            'service.httpd.installed' => 1,
            'service.httpd.enabled' => 1,
            'service.httpd.running' => 1,
        ], [])->codes);
    }

    public function test_a_stopped_service_that_is_not_enabled_or_installed_is_ignored(): void
    {
        $this->assertSame([], $this->detect($this->sample(), [
            'service.disabled.installed' => 1,
            'service.disabled.enabled' => 0,
            'service.disabled.running' => 0,
            'service.absent.enabled' => 1,
            'service.absent.running' => 0,
        ], [])->codes);

        // Missing state keys must not be read as "installed and enabled".
        $this->assertSame([], $this->detect($this->sample(), ['service.httpd.running' => 0], [])->codes);
    }

    public function test_the_cpanel_service_key_shape_is_the_contract(): void
    {
        // Exact keys emitted by agent/panels/cpanel.sh (whmapi1 servicestatus).
        $issues = $this->detect($this->sample(), [
            'service.apache.installed' => 1,
            'service.apache.enabled' => 1,
            'service.apache.running' => 1,
            'service.mysql.installed' => 1,
            'service.mysql.enabled' => 1,
            'service.mysql.running' => 0,
        ], []);

        $this->assertSame([AlertRule::ServiceDown->value], $issues->codes);
        $this->assertSame(['mysql'], $issues->details[AlertRule::ServiceDown->value]['value']);
    }

    // -- MySQL -------------------------------------------------------------

    public function test_mysql_unreachable_is_critical_only_when_availability_is_reported_zero(): void
    {
        $issues = $this->detect($this->sample(), ['mysql.available' => 0], []);

        $this->assertSame(ServerHealth::Critical, $issues->health);
        $this->assertSame(AlertSeverity::Critical->value, $issues->details[AlertRule::MysqlUnreachable->value]['severity']);

        $this->assertSame([], $this->detect($this->sample(), ['mysql.available' => 1], [])->codes);
        $this->assertSame([], $this->detect($this->sample(), ['mysql.version' => '8.0'], [])->codes);
    }

    // -- Quota -------------------------------------------------------------

    public function test_quota_over_is_a_warning(): void
    {
        $issues = $this->detect($this->sample(), ['diskquota.over' => 2], []);

        $this->assertSame(ServerHealth::Warning, $issues->health);
        $this->assertSame([
            'value' => 2,
            'threshold' => 0,
            'severity' => AlertSeverity::Warning->value,
        ], $issues->details[AlertRule::QuotaOver->value]);

        $this->assertSame([], $this->detect($this->sample(), ['diskquota.over' => 0], [])->codes);
    }

    // -- Module freshness --------------------------------------------------

    public function test_stale_module_data_is_reported_from_reported_cache_ages(): void
    {
        $issues = $this->detect($this->sample(), [
            'smart.cache_age_seconds' => 46 * 86400,
            'diskquota.cache_age_seconds' => 49 * 3600,
            'imunify.cache_age_seconds' => 31 * 60,
        ], []);

        $this->assertSame(ServerHealth::Warning, $issues->health);
        $this->assertSame([
            'value' => [
                'smart' => 46 * 86400,
                'diskquota' => 49 * 3600,
                'imunify' => 31 * 60,
            ],
            'threshold' => ['smart' => 45 * 86400, 'diskquota' => 48 * 3600, 'imunify' => 30 * 60],
            'severity' => AlertSeverity::Warning->value,
        ], $issues->details[AlertRule::ModuleCacheStale->value]);
    }

    public function test_fresh_module_data_is_not_stale(): void
    {
        $this->assertSame([], $this->detect($this->sample(), [
            'smart.cache_age_seconds' => 44 * 86400,
            'diskquota.cache_age_seconds' => 47 * 3600,
            'imunify.cache_age_seconds' => 29 * 60,
        ], [])->codes);
    }

    public function test_module_age_falls_back_to_updated_at_in_epoch_and_date_string_form(): void
    {
        $issues = $this->detect($this->sample(), [
            // now - 46 days, as epoch seconds.
            'smart.updated_at' => Carbon::now()->subDays(46)->getTimestamp(),
            // now - 49 hours, as an ISO 8601 string.
            'diskquota.updated_at' => Carbon::now()->subHours(49)->toIso8601String(),
        ], []);

        $this->assertSame([AlertRule::ModuleCacheStale->value], $issues->codes);
        $this->assertSame([
            'smart' => 46 * 86400,
            'diskquota' => 49 * 3600,
        ], $issues->details[AlertRule::ModuleCacheStale->value]['value']);
    }

    public function test_module_age_falls_back_to_mysql_updated_at(): void
    {
        $issues = $this->detect($this->sample(), [
            // mysql_minutes is 3: four minutes of silence is stale.
            'mysql.updated_at' => Carbon::now()->subMinutes(4)->getTimestamp(),
        ], []);

        $this->assertSame([AlertRule::ModuleCacheStale->value], $issues->codes);
        $this->assertSame(['mysql' => 4 * 60], $issues->details[AlertRule::ModuleCacheStale->value]['value']);
    }

    public function test_a_numeric_cache_age_wins_over_updated_at(): void
    {
        $issues = $this->detect($this->sample(), [
            'imunify.cache_age_seconds' => 60,
            'imunify.updated_at' => Carbon::now()->subDays(3)->getTimestamp(),
        ], []);

        $this->assertSame([], $issues->codes);
    }

    public function test_unparseable_module_timestamps_are_ignored(): void
    {
        $this->assertSame([], $this->detect($this->sample(), [
            'smart.updated_at' => 'not-a-date',
            'diskquota.cache_age_seconds' => 'unknown',
        ], [])->codes);
    }

    public function test_module_staleness_reads_its_thresholds_from_settings(): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.module_cache_stale.imunify_minutes', 5, 'int');

        $this->assertSame([AlertRule::ModuleCacheStale->value], $this->detect($this->sample(), [
            'imunify.cache_age_seconds' => 6 * 60,
        ], [])->codes);
    }

    // -- Security ----------------------------------------------------------

    public function test_security_disabled_is_ignored_until_an_admin_enables_it(): void
    {
        $this->assertSame([], $this->detect($this->sample(), [
            'security.csf_enabled' => 0,
            'security.modsec' => 'off',
        ], [])->codes);
    }

    public function test_security_disabled_triggers_when_enabled_and_reported_off(): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.security_disabled.enabled', true, 'bool');

        $issues = $this->detect($this->sample(), ['security.csf_enabled' => false], []);

        $this->assertSame(ServerHealth::Warning, $issues->health);
        $this->assertSame([
            'value' => ['csf'],
            'threshold' => 'enabled',
            'severity' => AlertSeverity::Warning->value,
        ], $issues->details[AlertRule::SecurityDisabled->value]);

        $this->assertSame(['modsec'], $this->detect($this->sample(), ['security.modsec' => '0'], [])->details[AlertRule::SecurityDisabled->value]['value']);
    }

    /**
     * `security.modsec` carries the raw `SecRuleEngine` value, so anything
     * outside the explicit disabled set is treated as enabled.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function securityModes(): array
    {
        return [
            'DetectionOnly is enabled' => ['DetectionOnly', false],
            'On is enabled' => ['On', false],
            'on is enabled' => ['on', false],
            'enabled is enabled' => ['enabled', false],
            'legacy 1 is enabled' => ['1', false],
            'Off is disabled' => ['Off', true],
            'off with padding is disabled' => ['  off  ', true],
            '0 is disabled' => ['0', true],
            'false is disabled' => ['false', true],
            'no is disabled' => ['no', true],
            'disabled is disabled' => ['disabled', true],
        ];
    }

    #[DataProvider('securityModes')]
    public function test_security_modsec_modes(string $mode, bool $disabled): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.security_disabled.enabled', true, 'bool');

        $issues = $this->detect($this->sample(), ['security.modsec' => $mode], []);

        $this->assertSame($disabled, $issues->has(AlertRule::SecurityDisabled->value));
    }

    public function test_the_cpanel_security_key_shape_is_the_contract(): void
    {
        // Exact keys emitted by agent/panels/cpanel.sh.
        $this->app->make(SettingsRepository::class)->set('alerts.rules.security_disabled.enabled', true, 'bool');

        $issues = $this->detect($this->sample(), [
            'security.csf_enabled' => 0,
            'security.modsec' => 'Off',
            'security.csf_testing' => '0',
        ], []);

        $this->assertSame(['csf', 'modsec'], $issues->details[AlertRule::SecurityDisabled->value]['value']);
    }

    public function test_empty_security_values_are_not_reported_and_stay_silent(): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.security_disabled.enabled', true, 'bool');

        $this->assertSame([], $this->detect($this->sample(), ['security.modsec' => '', 'security.csf_enabled' => null], [])->codes);
    }

    public function test_enabled_security_rules_on_servers_without_the_fields_stay_silent(): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.security_disabled.enabled', true, 'bool');

        $this->assertSame([], $this->detect($this->sample(), ['security.csf_enabled' => 1, 'security.modsec' => 'on'], [])->codes);
        $this->assertSame([], $this->detect($this->sample(), [], [])->codes);
    }

    public function test_security_disabled_honours_the_settings_switch(): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.security_disabled.enabled', true, 'bool');
        $this->assertSame([AlertRule::SecurityDisabled->value], $this->detect($this->sample(), ['security.modsec' => 'off'], [])->codes);

        $this->app->make(SettingsRepository::class)->set('alerts.rules.security_disabled.enabled', false, 'bool');
        $this->assertSame([], $this->detect($this->sample(), ['security.modsec' => 'off'], [])->codes);
    }

    public function test_a_disabled_rule_never_triggers(): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.memory_high.enabled', false, 'bool');

        $this->assertSame([], $this->detect($this->sample(mem: 99), [], [])->codes);
    }

    public function test_threshold_overrides_apply_to_resource_rules(): void
    {
        $this->app->make(SettingsRepository::class)->set('alerts.rules.disk_high.warning', 80, 'int');

        $this->assertSame([AlertRule::DiskHigh->value], $this->detect($this->sample(disk: 80), [], [])->codes);
        $this->assertSame([], $this->detect($this->sample(disk: 79.9), [], [])->codes);
    }

    // -- Aggregation -------------------------------------------------------

    public function test_health_aggregates_critical_over_warning_and_ok_when_clean(): void
    {
        $both = $this->detect($this->sample(mem: 96, disk: 86), [], []);

        $this->assertSame(ServerHealth::Critical, $both->health);
        $this->assertSame([AlertRule::MemoryHigh->value, AlertRule::DiskHigh->value], $both->codes);
        $this->assertSame(AlertSeverity::Critical->value, $both->details[AlertRule::MemoryHigh->value]['severity']);
        $this->assertSame(AlertSeverity::Warning->value, $both->details[AlertRule::DiskHigh->value]['severity']);

        $clean = $this->detect($this->sample(), [], []);

        $this->assertSame(ServerHealth::Ok, $clean->health);
        $this->assertSame([], $clean->codes);
        $this->assertSame([], $clean->details);
    }

    private function detect(MetricSample $latest, array $panelMetrics, array $extensions): IssueSet
    {
        return $this->detector->detect($latest->server, $latest, $panelMetrics, $extensions);
    }

    /**
     * Store three samples ending at the current test time and detect on the
     * newest one. `$cpu` drives `cpu_usage_pct`; `$loads` drives `load1`
     * (which defaults to a harmless 0.5 so CPU-only tests stay focused).
     *
     * @param  array{0: float, 1: float, 2: float}  $cpu
     * @param  array{0: float, 1: float, 2: float}|null  $loads
     */
    /**
     * Store a run of samples ending at the current test time and detect on the
     * newest one. `$cpu` drives `cpu_usage_pct`; `$loads` (when given) drives
     * `load1`, otherwise load stays at a harmless 0.5 so CPU-only tests remain
     * focused. The series length sets the window size.
     *
     * @param  list<float>  $cpu
     * @param  list<float>|null  $loads
     */
    private function detectHistory(array $cpu = [10, 10, 10], int $cores = 8, ?array $loads = null): IssueSet
    {
        $server = $this->server();
        $latest = null;
        $series = $loads ?? $cpu;
        $count = count($series);

        for ($index = 0; $index < $count; $index++) {
            $at = Carbon::now()->subMinutes($count - 1 - $index);

            $latest = $this->sample(
                usage: $cpu[$index] ?? 10.0,
                cores: $cores,
                load1: $loads === null ? 0.5 : (float) $loads[$index],
                at: $at,
                server: $server,
            );
        }

        return $this->detect($latest, [], []);
    }

    /**
     * An in-memory sample for states the schema cannot store (nullable
     * readings), shaped exactly like a stored row.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function unsavedSample(array $overrides): MetricSample
    {
        $at = Carbon::now();

        return (new MetricSample)->setRawAttributes([
            'server_id' => $this->server()->getKey(),
            'collected_at' => $at,
            'received_at' => $at,
            'schema_version' => 1,
            'interval_seconds' => 60,
            'cpu_usage_pct' => 10.0,
            'cpu_iowait_pct' => 0.1,
            'cpu_cores' => 8,
            'load1' => 0.5,
            'load5' => 0.5,
            'load15' => 0.5,
            'mem_total_bytes' => 1_000,
            'mem_used_bytes' => 100,
            'mem_used_pct' => 10.0,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0.0,
            'disk_total_bytes' => 1_000,
            'disk_used_bytes' => 100,
            'disk_used_pct' => 10.0,
            'disk_inode_pct' => 10.0,
            'net_rx_bps' => 0,
            'net_tx_bps' => 0,
            'processes_running' => 1,
            'processes_blocked' => 0,
            'uptime_seconds' => 100,
            'payload_bytes' => 10,
            ...$overrides,
        ]);
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'server-'.Str::lower(Str::ulid()),
            'hostname' => 'agent.example.test',
        ]);
    }

    private function sample(
        ?float $usage = 10.0,
        ?float $mem = 10.0,
        ?float $disk = 10.0,
        ?float $inode = 10.0,
        float $load1 = 0.5,
        int $cores = 8,
        ?Carbon $at = null,
        ?Server $server = null,
    ): MetricSample {
        $server ??= $this->server();

        return $server->samples()->create([
            'collected_at' => $at ?? Carbon::now(),
            'received_at' => $at ?? Carbon::now(),
            'schema_version' => 1,
            'interval_seconds' => 60,
            'cpu_usage_pct' => $usage,
            'cpu_iowait_pct' => 0.1,
            'cpu_cores' => $cores,
            'load1' => $load1,
            'load5' => $load1,
            'load15' => $load1,
            'mem_total_bytes' => 1_000,
            'mem_used_bytes' => 100,
            'mem_used_pct' => $mem,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0,
            'disk_total_bytes' => 1_000,
            'disk_used_bytes' => 100,
            'disk_used_pct' => $disk,
            'disk_inode_pct' => $inode,
            'net_rx_bps' => 0,
            'net_tx_bps' => 0,
            'processes_running' => 1,
            'processes_blocked' => 0,
            'uptime_seconds' => 100,
            'payload_bytes' => 10,
        ])->refresh();
    }
}
