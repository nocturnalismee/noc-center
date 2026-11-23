<?php

namespace App\Issues;

use App\Enums\AlertRule;
use App\Enums\AlertSeverity;
use App\Models\MetricSample;
use App\Models\Server;
use App\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Evaluate the ingest-time issue rules (spec §9.1) for one sample.
 *
 * Every threshold is read through `SettingsRepository` with the
 * `config/alerts.php` value as the default, so an admin override on the alert
 * settings page (Task 20) changes behaviour without a deploy.
 *
 * Rules never treat a missing key as zero: a module that was not reported, a
 * service without its state keys, or a server without `mysql.available` stays
 * silent instead of producing fake incidents.
 *
 * `cpu_high` and `load_high` look at the latest three stored samples (R6
 * permits the extra indexed query). All three must clear the warning line;
 * the issue is critical only when all three clear the critical line.
 */
class IssueDetector
{
    /**
     * Agent flat-map key shapes: `service.<name>.<state>` and
     * `smart.disk.<device>.<attribute>`. The captured segments cannot contain
     * dots or quotes, so a hostile key cannot inject syntax into a lookup.
     */
    private const SERVICE_KEY = '/\Aservice\.([A-Za-z0-9_\-]+)\.(installed|enabled|running)\z/';

    /**
     * `realloc` / `pending` are the keys shipped by `agent/modules/smart.sh`;
     * the `_sectors` suffixes are tolerated for older collectors.
     */
    private const SMART_DISK_KEY = '/\Asmart\.disk\.([A-Za-z0-9_\-]+)\.(realloc|pending|reallocated_sectors|pending_sectors)\z/';

    /**
     * `security.modsec` carries the raw `SecRuleEngine` value and
     * `security.csf_enabled` a 0/1 flag. Anything outside this set (including
     * `DetectionOnly` and `On`) means the engine is active.
     */
    private const DISABLED_VALUES = ['0', 'false', 'off', 'no', 'disabled'];

    /**
     * Fallback when `alerts.rules.<rule>.consecutive_samples` is not configured.
     */
    private const CONSECUTIVE_SAMPLES = 3;

    public function __construct(private readonly SettingsRepository $settings)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $panelMetrics  panel flat map
     * @param  array<string, mixed>  $extensions  module flat map (SMART, quota, Imunify, MySQL)
     */
    public function detect(Server $server, MetricSample $latest, array $panelMetrics, array $extensions): IssueSet
    {
        $now = CarbonImmutable::now('UTC');

        $found = array_values(array_filter([
            $this->cpuHigh($server, $latest),
            $this->percentage($latest->mem_used_pct, AlertRule::MemoryHigh),
            $this->percentage($latest->disk_used_pct, AlertRule::DiskHigh),
            $this->percentage($latest->disk_inode_pct, AlertRule::InodeHigh),
            $this->loadHigh($server, $latest),
            $this->smartStatus($panelMetrics, $extensions) ?? $this->smartSectors($panelMetrics, $extensions),
            $this->serviceDown($panelMetrics, $extensions),
            $this->mysqlUnreachable($panelMetrics),
            $this->quotaOver($panelMetrics),
            $this->moduleCacheStale($panelMetrics, $extensions, $now),
            $this->securityDisabled($panelMetrics),
        ]));

        $codes = [];
        $details = [];

        foreach ($found as $issue) {
            $codes[] = $issue['code']->value;
            $details[$issue['code']->value] = $issue['detail'];
        }

        return new IssueSet($codes, $details);
    }

    /**
     * A percentage rule evaluated on the sample alone. Zero is a real reading
     * (an empty disk is 0% used), so only a null column is skipped.
     */
    private function percentage(?float $value, AlertRule $rule): ?array
    {
        if ($value === null || ! $this->enabled($rule)) {
            return null;
        }

        return $this->compare($value, (float) $this->threshold($rule, 'warning'), (float) $this->threshold($rule, 'critical'), false, $rule);
    }

    private function cpuHigh(Server $server, MetricSample $latest): ?array
    {
        if (! $this->enabled(AlertRule::CpuHigh) || $latest->cpu_usage_pct === null) {
            return null;
        }

        $window = $this->consecutiveSamples(AlertRule::CpuHigh);

        return $this->consecutive(
            $this->history($server, $latest, 'cpu_usage_pct', $window),
            $latest->cpu_usage_pct,
            (float) $this->threshold(AlertRule::CpuHigh, 'warning'),
            (float) $this->threshold(AlertRule::CpuHigh, 'critical'),
            $window,
            AlertRule::CpuHigh,
        );
    }

    private function loadHigh(Server $server, MetricSample $latest): ?array
    {
        if (! $this->enabled(AlertRule::LoadHigh) || $latest->load1 === null) {
            return null;
        }

        // `cpu_cores` lives inside the per-sample `cpu` block. Without a core
        // count on this sample there is no scale to compare against, so the
        // rule stays silent instead of borrowing another sample's value: a
        // host that resized between samples would be judged against the wrong
        // scale.
        $cores = $latest->cpu_cores;

        if ($cores === null || $cores <= 0) {
            return null;
        }

        $warning = $cores * (float) $this->threshold(AlertRule::LoadHigh, 'warning_multiplier');
        $critical = $cores * (float) $this->threshold(AlertRule::LoadHigh, 'critical_multiplier');
        $window = $this->consecutiveSamples(AlertRule::LoadHigh);

        return $this->consecutive(
            $this->history($server, $latest, 'load1', $window),
            $latest->load1,
            $warning,
            $critical,
            $window,
            AlertRule::LoadHigh,
        );
    }

    /**
     * The samples before `$latest`, oldest first. The query walks the
     * `(server_id, collected_at)` index backwards and is bounded to the window.
     * A null metric inside the window means the history is not "all samples
     * over the line"; it is never compared as zero.
     *
     * @return list<float|null>
     */
    private function history(Server $server, MetricSample $latest, string $column, int $window): array
    {
        $previous = MetricSample::query()
            ->where('server_id', $server->getKey())
            ->where('collected_at', '<', $latest->collected_at)
            ->orderByDesc('collected_at')
            ->limit(max(0, $window - 1))
            ->pluck($column)
            ->all();

        return array_reverse(array_map(
            static fn (mixed $value): ?float => $value === null ? null : (float) $value,
            $previous,
        ));
    }

    /**
     * Require the whole window (history plus the newest sample) to clear the
     * warning line; the issue is critical only if every sample also clears the
     * critical line. The reported detail follows the newest sample.
     *
     * @param  list<float|null>  $history
     */
    private function consecutive(array $history, float $value, float $warning, float $critical, int $window, AlertRule $rule): ?array
    {
        // A short history (right after onboarding) is not an incident.
        if (count($history) < $window - 1) {
            return null;
        }

        $samples = [...$history, $value];
        $last = count($samples) - 1;
        $newest = null;

        foreach ($samples as $index => $sample) {
            if ($sample === null) {
                return null;
            }

            // Load uses a strict `>` (load equal to cores is saturated, not
            // overloaded); the percentage rules use `>=`.
            $issue = $this->compare($sample, $warning, $critical, $rule === AlertRule::LoadHigh, $rule);

            if ($issue === null) {
                return null;
            }

            if ($index === $last) {
                $newest = $issue;
            }
        }

        return $newest;
    }

    /**
     * @return array{code: AlertRule, detail: array{value: mixed, threshold: mixed, severity: string}}|null
     */
    private function compare(float $value, float $warning, float $critical, bool $strict, AlertRule $rule): ?array
    {
        $overCritical = $strict ? $value > $critical : $value >= $critical;
        $overWarning = $strict ? $value > $warning : $value >= $warning;

        if ($overCritical) {
            return $this->issue($rule, $value, $this->reported($critical), AlertSeverity::Critical);
        }

        if ($overWarning) {
            return $this->issue($rule, $value, $this->reported($warning), AlertSeverity::Warning);
        }

        return null;
    }

    /**
     * SMART `status`. `failed` is critical and also wins over sector counters.
     */
    private function smartStatus(array $panelMetrics, array $extensions): ?array
    {
        $status = $this->key([$panelMetrics, $extensions], 'smart.status');

        if (! is_string($status)) {
            return null;
        }

        $status = Str::lower($status);

        if ($status === 'failed' && $this->enabled(AlertRule::SmartFailed)) {
            return $this->issue(AlertRule::SmartFailed, $status, 'failed', AlertSeverity::Critical);
        }

        if ($status === 'warning' && $this->enabled(AlertRule::SmartWarning)) {
            return $this->issue(AlertRule::SmartWarning, $status, 'warning', AlertSeverity::Warning);
        }

        return null;
    }

    /**
     * A pending or reallocated sector is a real defect; 0 is healthy.
     */
    private function smartSectors(array $panelMetrics, array $extensions): ?array
    {
        if (! $this->enabled(AlertRule::SmartWarning)) {
            return null;
        }

        foreach ([$panelMetrics, $extensions] as $map) {
            foreach ($map as $key => $value) {
                if (preg_match(self::SMART_DISK_KEY, (string) $key) !== 1) {
                    continue;
                }

                $count = $this->number($value);

                if ($count !== null && $count > 0) {
                    return $this->issue(AlertRule::SmartWarning, $value, '> 0', AlertSeverity::Warning);
                }
            }
        }

        return null;
    }

    /**
     * Installed and enabled but not running. Only an explicit report for all
     * three states can be judged; a partial report is not "installed".
     */
    private function serviceDown(array $panelMetrics, array $extensions): ?array
    {
        if (! $this->enabled(AlertRule::ServiceDown)) {
            return null;
        }

        $services = [];

        foreach ([$panelMetrics, $extensions] as $map) {
            foreach ($map as $key => $value) {
                if (preg_match(self::SERVICE_KEY, (string) $key, $matches) === 1) {
                    $services[$matches[1]][$matches[2]] ??= $value;
                }
            }
        }

        $down = [];

        foreach ($services as $name => $states) {
            if (($states['installed'] ?? null) === null || ! $this->truthy($states['installed'])) {
                continue;
            }

            if (($states['enabled'] ?? null) === null || ! $this->truthy($states['enabled'])) {
                continue;
            }

            if (($states['running'] ?? null) !== null && ! $this->truthy($states['running'])) {
                $down[] = (string) $name;
            }
        }

        if ($down === []) {
            return null;
        }

        sort($down);

        return $this->issue(AlertRule::ServiceDown, $down, 'installed && enabled && running = 0', AlertSeverity::Critical);
    }

    private function mysqlUnreachable(array $panelMetrics): ?array
    {
        $available = $this->key([$panelMetrics], 'mysql.available');

        if (! $this->enabled(AlertRule::MysqlUnreachable) || $available === null) {
            return null;
        }

        $value = $this->number($available);

        if ($value === null || $value != 0) {
            return null;
        }

        return $this->issue(AlertRule::MysqlUnreachable, $available, 1, AlertSeverity::Critical);
    }

    private function quotaOver(array $panelMetrics): ?array
    {
        $over = $this->key([$panelMetrics], 'diskquota.over');

        if (! $this->enabled(AlertRule::QuotaOver) || $over === null) {
            return null;
        }

        $value = $this->number($over);

        if ($value === null || $value <= 0) {
            return null;
        }

        return $this->issue(AlertRule::QuotaOver, $over, 0, AlertSeverity::Warning);
    }

    /**
     * Compare each module's reported age with its freshness budget.
     *
     * `cache_age_seconds` is used when numeric; otherwise the age is
     * `now - <module>.updated_at` (epoch seconds or a date string). Absent or
     * unparseable data never triggers.
     */
    private function moduleCacheStale(array $panelMetrics, array $extensions, CarbonImmutable $now): ?array
    {
        if (! $this->enabled(AlertRule::ModuleCacheStale)) {
            return null;
        }

        $thresholds = [
            'smart' => (int) $this->threshold(AlertRule::ModuleCacheStale, 'smart_days') * 86400,
            'diskquota' => (int) $this->threshold(AlertRule::ModuleCacheStale, 'diskquota_hours') * 3600,
            'imunify' => (int) $this->threshold(AlertRule::ModuleCacheStale, 'imunify_minutes') * 60,
            'mysql' => (int) $this->threshold(AlertRule::ModuleCacheStale, 'mysql_minutes') * 60,
        ];

        $maps = [$panelMetrics, $extensions];
        $ages = [];

        foreach ($thresholds as $module => $threshold) {
            $age = $this->moduleAge($maps, $module, $now);

            if ($age !== null && $age > $threshold) {
                $ages[$module] = $age;
            }
        }

        if ($ages === []) {
            return null;
        }

        return $this->issue(
            AlertRule::ModuleCacheStale,
            $ages,
            array_intersect_key($thresholds, $ages),
            AlertSeverity::Warning,
        );
    }

    /**
     * A module's cache age in seconds.
     *
     * A numeric `<module>.cache_age_seconds` wins, because the agent knows the
     * exact age of its own cache. Only when that is absent (or not numeric) is
     * the age derived as `now - <module>.updated_at`, which accepts epoch
     * seconds or a date string. Unparseable values are "unknown", never
     * "ancient", so they never trigger.
     */
    private function moduleAge(array $maps, string $module, CarbonImmutable $now): ?int
    {
        $reported = $this->key($maps, $module.'.cache_age_seconds');

        if ($reported !== null) {
            $age = $this->number($reported);

            if ($age !== null) {
                return max(0, (int) round($age));
            }
        }

        $updatedAt = $this->key($maps, $module.'.updated_at');

        if ($updatedAt === null || is_bool($updatedAt) || is_array($updatedAt)) {
            return null;
        }

        // Epoch seconds or a date string; an unparseable value is not "very
        // old", it is unknown.
        $epoch = $this->number($updatedAt);
        $at = $epoch !== null
            ? CarbonImmutable::createFromTimestamp((int) $epoch, 'UTC')
            : $this->parseDate($updatedAt);

        return $at === null ? null : max(0, $now->getTimestamp() - $at->getTimestamp());
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Opt-in rule (default off): CSF or ModSecurity explicitly reported as
     * disabled. Only an explicit 0/false/off counts; a missing field is
     * "Not reported", not "disabled".
     */
    private function securityDisabled(array $panelMetrics): ?array
    {
        if (! $this->enabled(AlertRule::SecurityDisabled)) {
            return null;
        }

        $disabled = [];

        foreach (['csf_enabled' => 'csf', 'modsec' => 'modsec'] as $field => $name) {
            $value = $this->key([$panelMetrics], 'security.'.$field);

            if ($this->reportedOff($value)) {
                $disabled[] = $name;
            }
        }

        if ($disabled === []) {
            return null;
        }

        return $this->issue(AlertRule::SecurityDisabled, $disabled, 'enabled', AlertSeverity::Warning);
    }

    /**
     * `security.modsec` is the raw `SecRuleEngine` value (`On`,
     * `DetectionOnly`, `Off`) and `security.csf_enabled` a 0/1 flag. Only the
     * explicit disabled set counts, after trim+lowercase; anything else
     * (including `DetectionOnly`) means active. Missing, null or blank is
     * "not reported" and stays silent.
     */
    private function reportedOff(mixed $value): bool
    {
        if (is_bool($value)) {
            return ! $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value == 0;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = Str::lower(trim($value));

        return $normalized !== '' && in_array($normalized, self::DISABLED_VALUES, true);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_string($value)) {
            return in_array(Str::lower(trim($value)), ['1', 'true', 'on', 'yes', 'enabled'], true);
        }

        return false;
    }

    /**
     * First non-null value across the maps (panel first). A key that is absent
     * or null is never treated as a value.
     *
     * @param  array<int, array<string, mixed>>  $maps
     */
    private function key(array $maps, string $key): mixed
    {
        foreach ($maps as $map) {
            if (array_key_exists($key, $map) && $map[$key] !== null) {
                return $map[$key];
            }
        }

        return null;
    }

    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * Rule switch, then threshold, both from `config/alerts.php` unless an
     * admin override exists.
     */
    private function enabled(AlertRule $rule): bool
    {
        return filter_var($this->settings('enabled', $rule), FILTER_VALIDATE_BOOLEAN);
    }

    private function threshold(AlertRule $rule, string $key): mixed
    {
        return $this->settings($key, $rule);
    }

    /**
     * The number of consecutive samples a window rule requires. Honours
     * `alerts.rules.<rule>.consecutive_samples`; a non-positive or non-numeric
     * override falls back to the default rather than disabling the rule.
     */
    private function consecutiveSamples(AlertRule $rule): int
    {
        $value = $this->number($this->threshold($rule, 'consecutive_samples'));

        return $value !== null && $value >= 1 ? (int) $value : self::CONSECUTIVE_SAMPLES;
    }

    private function settings(string $key, AlertRule $rule): mixed
    {
        return $this->settings->get(
            'alerts.rules.'.$rule->value.'.'.$key,
            $this->configured($rule, $key),
        );
    }

    /**
     * Read one value from `config/alerts.php`.
     *
     * Uses `config()->get()` rather than the `config($key, $default)` helper:
     * no rule call site may pass a fallback as the helper's second argument,
     * because a dynamic `'alerts.rules.'.$rule.'.'.$key` path plus that second
     * argument reads as "return the second argument" instead of a config
     * lookup (verified on PHP 8.4), which made the load multipliers read a
     * literal instead of 1.0/2.0.
     */
    private function configured(AlertRule $rule, string $key): mixed
    {
        return config()->get('alerts.rules.'.$rule->value.'.'.$key);
    }

    /**
     * @return array{code: AlertRule, detail: array{value: mixed, threshold: mixed, severity: string}}
     */
    private function issue(AlertRule $rule, mixed $value, mixed $threshold, AlertSeverity $severity): array
    {
        return [
            'code' => $rule,
            'detail' => [
                'value' => $value,
                'threshold' => $threshold,
                'severity' => $severity->value,
            ],
        ];
    }

    private function reported(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }
}
