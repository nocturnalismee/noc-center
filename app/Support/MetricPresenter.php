<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turn the agent's flat panel/module maps into bounded, escaped-by-Blade
 * presentation rows. Unknown keys are humanised, never trusted as markup.
 */
class MetricPresenter
{
    private const MAX_GENERIC_ROWS = 200;

    private const MAX_SERVICE_ROWS = 100;

    private const MAX_SMART_DISKS = 100;

    private const MAX_QUERIES = 50;

    private const MAX_QUOTA_USERS = 100;

    private const GROUP_ORDER = ['cpanel', 'security', 'mysql', 'other'];

    /**
     * @param  array<string, mixed>  $flat
     * @return array{
     *     groups: list<array{key:string,label:string,rows:list<array{key:string,label:string,value:string,type:string}>}>,
     *     services: list<array{name:string,installed:string,enabled:string,running:string,severity:string}>,
     *     smart: array{status:string,status_label:string,detail:string,cache_age:string,disks:list<array{device:string,health:string,health_label:string,realloc:string,pending:string,power_on_hours:string,written_gb:string}>},
     *     quota: array{accounts:string,over:string,cache_age:string,top:list<array{user:string,used_gb:string}>},
     *     imunify: array{scanning:string,age:string,files:string,target:string,cache_age:string},
     *     queries: list<array{user:string,db:string,state:string,time:string,info:string}>,
     *     truncated: bool,
     *     generic_shown: int,
     *     generic_total: int,
     *     module_truncated: bool,
     *     total: int
     * }
     */
    public static function groups(array $flat): array
    {
        $groups = [];
        $services = [];
        $smartDisks = [];
        $quotaTop = [];
        $queries = [];
        $truncated = false;
        $moduleTruncated = false;
        $genericRows = 0;
        $genericTotal = 0;

        foreach ($flat as $rawKey => $rawValue) {
            if (! is_string($rawKey) || $rawKey === '' || ! is_scalar($rawValue)) {
                continue;
            }

            $key = Str::limit($rawKey, 160, '');
            $value = $rawValue;
            $prefix = explode('.', $key, 2)[0] ?? 'other';

            if ($prefix === 'service') {
                if (preg_match('/^service\.([^.]+)\.([A-Za-z0-9_]+)$/', $key, $matches) === 1) {
                    $name = self::safeName($matches[1]);
                    $field = $matches[2];
                    $services[$name] ??= ['name' => $name, 'installed' => null, 'enabled' => null, 'running' => null];
                    if (in_array($field, ['installed', 'enabled', 'running'], true)) {
                        $services[$name][$field] = self::booleanOrNull($value);
                    }
                }

                continue;
            }

            if ($prefix === 'mysql' && preg_match('/^mysql\.query\.(\d+)\.([A-Za-z0-9_]+)$/', $key, $matches) === 1) {
                $index = (int) $matches[1];
                $field = $matches[2];
                $queries[$index] ??= ['user' => '', 'db' => '', 'state' => '', 'time' => '', 'info' => ''];
                if (array_key_exists($field, $queries[$index])) {
                    $queries[$index][$field] = $field === 'info'
                        ? (string) MysqlQueryMasker::mask(is_string($value) ? $value : (string) $value)
                        : MetricFormatter::text($value);
                }

                continue;
            }

            if ($prefix === 'smart' && preg_match('/^smart\.disk\.([^.]+)\.([A-Za-z0-9_]+)$/', $key, $matches) === 1) {
                $device = self::safeName($matches[1]);
                $field = $matches[2];
                $smartDisks[$device] ??= [];
                if (in_array($field, ['health', 'realloc', 'pending', 'power_on_hours', 'written_gb'], true)) {
                    $smartDisks[$device][$field] = $value;
                }

                continue;
            }

            if ($prefix === 'diskquota' && preg_match('/^diskquota\.top\.(.+)\.used_gb$/', $key, $matches) === 1) {
                $quotaTop[] = ['user' => self::safeName($matches[1]), 'used_gb' => MetricFormatter::format($value, 'count')];

                continue;
            }

            if (in_array($prefix, ['smart', 'diskquota', 'imunify'], true)) {
                continue; // handled by the dedicated sections below
            }

            if (! in_array($prefix, self::GROUP_ORDER, true)) {
                $prefix = 'other';
            }

            $genericTotal++;

            if ($genericRows >= self::MAX_GENERIC_ROWS) {
                $truncated = true;

                continue;
            }

            $definition = MetricCatalog::panelDefinition($key);
            $type = $definition['type'];
            $label = $definition['label'];

            $groups[$prefix] ??= ['key' => $prefix, 'label' => MetricCatalog::groupLabel($prefix), 'rows' => []];
            $groups[$prefix]['rows'][] = [
                'key' => $key,
                'label' => $label,
                'value' => MetricFormatter::format($value, $type),
                'type' => $type,
            ];
            $genericRows++;
        }

        $orderedGroups = [];
        foreach (self::GROUP_ORDER as $key) {
            if (isset($groups[$key]) && $groups[$key]['rows'] !== []) {
                usort($groups[$key]['rows'], fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
                $orderedGroups[] = $groups[$key];
            }
        }

        ksort($services, SORT_NATURAL | SORT_FLAG_CASE);
        $serviceRows = [];
        foreach (array_slice($services, 0, self::MAX_SERVICE_ROWS, true) as $service) {
            $serviceRows[] = [
                'name' => $service['name'],
                'installed' => MetricFormatter::boolLabel($service['installed']),
                'enabled' => MetricFormatter::boolLabel($service['enabled']),
                'running' => MetricFormatter::boolLabel($service['running']),
                'severity' => self::serviceSeverity($service),
            ];
        }

        if (count($services) > self::MAX_SERVICE_ROWS) {
            $truncated = true;
            $moduleTruncated = true;
        }

        $smart = [
            'status' => MetricFormatter::text($flat['smart.status'] ?? ''),
            'status_label' => self::smartStatusLabel($flat['smart.status'] ?? null),
            'detail' => MetricFormatter::text($flat['smart.detail'] ?? ''),
            'cache_age' => MetricFormatter::format($flat['smart.cache_age_seconds'] ?? null, 'seconds'),
            'disks' => [],
        ];

        ksort($smartDisks);
        foreach (array_slice($smartDisks, 0, self::MAX_SMART_DISKS, true) as $device => $fields) {
            $health = self::diskHealth($fields['health'] ?? null);
            $smart['disks'][] = [
                'device' => $device,
                'health' => $health,
                'health_label' => ucfirst($health),
                'realloc' => MetricFormatter::format($fields['realloc'] ?? null, 'count'),
                'pending' => MetricFormatter::format($fields['pending'] ?? null, 'count'),
                'power_on_hours' => MetricFormatter::format($fields['power_on_hours'] ?? null, 'count'),
                'written_gb' => MetricFormatter::format($fields['written_gb'] ?? null, 'count'),
            ];
        }

        if (count($smartDisks) > self::MAX_SMART_DISKS) {
            $truncated = true;
            $moduleTruncated = true;
        }

        usort($quotaTop, fn (array $a, array $b): int => (float) $b['used_gb'] <=> (float) $a['used_gb']);
        $quota = [
            'accounts' => MetricFormatter::format($flat['diskquota.accounts'] ?? null, 'count'),
            'over' => MetricFormatter::format($flat['diskquota.over'] ?? null, 'count'),
            'cache_age' => MetricFormatter::format($flat['diskquota.cache_age_seconds'] ?? null, 'seconds'),
            'top' => array_slice(array_map(fn (array $row): array => [
                'user' => $row['user'],
                'used_gb' => $row['used_gb'],
            ], $quotaTop), 0, self::MAX_QUOTA_USERS),
        ];

        if (count($quotaTop) > self::MAX_QUOTA_USERS) {
            $truncated = true;
            $moduleTruncated = true;
        }

        $imunify = [
            'scanning' => MetricFormatter::boolLabel($flat['imunify.scanning'] ?? null),
            'age' => MetricFormatter::format($flat['imunify.age_s'] ?? null, 'seconds'),
            'files' => MetricFormatter::format($flat['imunify.files'] ?? null, 'count'),
            'target' => MetricFormatter::text($flat['imunify.target'] ?? ''),
            'cache_age' => MetricFormatter::format($flat['imunify.cache_age_seconds'] ?? null, 'seconds'),
        ];

        ksort($queries);
        $queryRows = [];
        foreach (array_slice($queries, 0, self::MAX_QUERIES, true) as $query) {
            $queryRows[] = [
                'user' => $query['user'] !== '' ? $query['user'] : '—',
                'db' => $query['db'] !== '' ? $query['db'] : '—',
                'state' => $query['state'] !== '' ? $query['state'] : '—',
                'time' => $query['time'] !== '' ? $query['time'] : '—',
                'info' => $query['info'] !== '' ? Str::limit($query['info'], 500, '…') : '—',
            ];
        }

        if (count($queries) > self::MAX_QUERIES) {
            $truncated = true;
        }

        return [
            'groups' => $orderedGroups,
            'services' => $serviceRows,
            'smart' => $smart,
            'quota' => $quota,
            'imunify' => $imunify,
            'queries' => $queryRows,
            'truncated' => $truncated,
            'generic_shown' => $genericRows,
            'generic_total' => $genericTotal,
            'module_truncated' => $moduleTruncated,
            'total' => count($flat),
        ];
    }

    private static function safeName(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = preg_replace('/[^A-Za-z0-9_.@:-]+/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? '—' : Str::limit($value, 80, '');
    }

    private static function booleanOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function diskHealth(mixed $value): string
    {
        return match (strtoupper(MetricFormatter::text($value))) {
            'PASSED', 'OK', 'HEALTHY' => 'healthy',
            'WARNING', 'WARN' => 'warning',
            'FAILED', 'FAIL', 'CRITICAL' => 'failed',
            default => 'unknown',
        };
    }

    private static function smartStatusLabel(mixed $status): string
    {
        $value = strtolower((string) $status);

        return match ($value) {
            'ok', 'healthy', 'passed' => 'Healthy',
            'warning', 'warn' => 'Warning',
            'failed', 'fail', 'critical' => 'Failed',
            'unknown', '' => 'Unknown',
            default => Str::limit((string) $status, 40, ''),
        };
    }

    /**
     * @param  array{installed:?bool,enabled:?bool,running:?bool}  $service
     */
    private static function serviceSeverity(array $service): string
    {
        if ($service['installed'] === false) {
            return 'unknown';
        }

        if ($service['installed'] === true && $service['running'] === false) {
            return 'critical';
        }

        if ($service['enabled'] === false) {
            return 'warning';
        }

        if ($service['running'] === true) {
            return 'ok';
        }

        return 'unknown';
    }
}
