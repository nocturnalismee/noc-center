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
     *     truncated: bool
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
        $genericRows = 0;

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
                        $services[$name][$field] = (bool) $value;
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
                        : self::text($value);
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
                $quotaTop[] = ['user' => self::safeName($matches[1]), 'used_gb' => self::number($value)];

                continue;
            }

            if (in_array($prefix, ['smart', 'diskquota', 'imunify'], true)) {
                continue; // handled by the dedicated sections below
            }

            if (! in_array($prefix, self::GROUP_ORDER, true)) {
                $prefix = 'other';
            }

            if ($genericRows >= self::MAX_GENERIC_ROWS) {
                $truncated = true;

                continue;
            }

            $definition = config('metric_labels.keys.'.$key);
            $type = is_array($definition) ? (string) ($definition['type'] ?? 'text') : 'text';
            $label = is_array($definition) ? (string) ($definition['label'] ?? '') : '';
            $label = $label !== '' ? $label : self::keyLabel($key);

            $groups[$prefix] ??= ['key' => $prefix, 'label' => (string) config('metric_labels.groups.'.$prefix, 'Other metrics'), 'rows' => []];
            $groups[$prefix]['rows'][] = [
                'key' => $key,
                'label' => $label,
                'value' => self::format($value, $type),
                'type' => $type,
            ];
            $genericRows++;
        }

        $orderedGroups = [];
        foreach (self::GROUP_ORDER as $key) {
            if (isset($groups[$key]) && $groups[$key]['rows'] !== []) {
                $orderedGroups[] = $groups[$key];
            }
        }

        $serviceRows = [];
        foreach (array_slice($services, 0, self::MAX_SERVICE_ROWS, true) as $service) {
            $serviceRows[] = [
                'name' => $service['name'],
                'installed' => self::boolLabel($service['installed']),
                'enabled' => self::boolLabel($service['enabled']),
                'running' => self::boolLabel($service['running']),
                'severity' => self::serviceSeverity($service),
            ];
        }

        if (count($services) > self::MAX_SERVICE_ROWS) {
            $truncated = true;
        }

        $smart = [
            'status' => self::text($flat['smart.status'] ?? ''),
            'status_label' => self::smartStatusLabel($flat['smart.status'] ?? null),
            'detail' => self::text($flat['smart.detail'] ?? ''),
            'cache_age' => self::format($flat['smart.cache_age_seconds'] ?? null, 'seconds'),
            'disks' => [],
        ];

        ksort($smartDisks);
        foreach (array_slice($smartDisks, 0, self::MAX_SMART_DISKS, true) as $device => $fields) {
            $health = $fields['health'] ?? null;
            $smart['disks'][] = [
                'device' => $device,
                'health' => self::text($health),
                'health_label' => ucfirst(strtolower(self::text($health))),
                'realloc' => self::format($fields['realloc'] ?? null, 'count'),
                'pending' => self::format($fields['pending'] ?? null, 'count'),
                'power_on_hours' => self::format($fields['power_on_hours'] ?? null, 'count'),
                'written_gb' => self::format($fields['written_gb'] ?? null, 'count'),
            ];
        }

        if (count($smartDisks) > self::MAX_SMART_DISKS) {
            $truncated = true;
        }

        usort($quotaTop, fn (array $a, array $b): int => (float) $b['used_gb'] <=> (float) $a['used_gb']);
        $quota = [
            'accounts' => self::format($flat['diskquota.accounts'] ?? null, 'count'),
            'over' => self::format($flat['diskquota.over'] ?? null, 'count'),
            'cache_age' => self::format($flat['diskquota.cache_age_seconds'] ?? null, 'seconds'),
            'top' => array_slice(array_map(fn (array $row): array => [
                'user' => $row['user'],
                'used_gb' => self::number($row['used_gb']),
            ], $quotaTop), 0, self::MAX_QUOTA_USERS),
        ];

        if (count($quotaTop) > self::MAX_QUOTA_USERS) {
            $truncated = true;
        }

        $imunify = [
            'scanning' => self::boolLabel($flat['imunify.scanning'] ?? null),
            'age' => self::format($flat['imunify.age_s'] ?? null, 'seconds'),
            'files' => self::format($flat['imunify.files'] ?? null, 'count'),
            'target' => self::text($flat['imunify.target'] ?? ''),
            'cache_age' => self::format($flat['imunify.cache_age_seconds'] ?? null, 'seconds'),
        ];

        ksort($queries);
        $queryRows = [];
        foreach (array_slice($queries, 0, self::MAX_QUERIES, true) as $query) {
            $queryRows[] = [
                'user' => $query['user'] !== '' ? $query['user'] : '—',
                'db' => $query['db'] !== '' ? $query['db'] : '—',
                'state' => $query['state'] !== '' ? $query['state'] : '—',
                'time' => $query['time'] !== '' ? $query['time'] : '—',
                'info' => $query['info'] !== '' ? $query['info'] : '—',
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
            'total' => count($flat),
        ];
    }

    private static function keyLabel(string $key): string
    {
        $segments = explode('.', $key);
        $last = end($segments) ?: $key;
        $known = config('metric_labels.fields.'.$last);

        if (is_string($known) && $known !== '') {
            return $known;
        }

        $label = preg_replace('/[^A-Za-z0-9]+/', ' ', $last) ?? $last;
        $label = trim($label);

        return $label === '' ? 'Value' : Str::limit(ucfirst(strtolower($label)), 80, '');
    }

    private static function safeName(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = preg_replace('/[^A-Za-z0-9_.@:-]+/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? '—' : Str::limit($value, 80, '');
    }

    private static function format(mixed $value, string $type): string
    {
        if ($value === null) {
            return 'Not reported';
        }

        return match ($type) {
            'bool' => self::boolLabel($value),
            'count' => self::number($value),
            'percent' => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%' : self::text($value),
            'bytes' => self::bytes($value),
            'seconds' => self::seconds($value),
            'duration' => self::duration($value),
            default => self::text($value),
        };
    }

    private static function number(mixed $value): string
    {
        if (! is_numeric($value)) {
            return self::text($value);
        }

        $number = (float) $value;

        return abs($number - round($number)) < 0.005
            ? number_format($number, 0, '.', '')
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private static function bytes(mixed $value): string
    {
        if (! is_numeric($value)) {
            return self::text($value);
        }

        $bytes = max(0, (float) $value);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return self::number($bytes).' '.$units[$index];
    }

    private static function seconds(mixed $value): string
    {
        if (! is_numeric($value)) {
            return self::text($value);
        }

        $seconds = max(0, (int) $value);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600).'h';
        }

        return intdiv($seconds, 86400).'d';
    }

    private static function duration(mixed $value): string
    {
        if (! is_numeric($value)) {
            return self::text($value);
        }

        $seconds = max(0, (int) $value);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        return $days > 0 ? $days.'d '.$hours.'h' : $hours.'h';
    }

    private static function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '—';
        }

        $text = trim((string) $value);

        if ($text === '') {
            return '—';
        }

        return Str::limit($text, 300, '…');
    }

    private static function boolLabel(mixed $value): string
    {
        if ($value === null) {
            return 'Not reported';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $text = strtolower(trim((string) $value));

        return match ($text) {
            '1', 'true', 'yes', 'on', 'enabled', 'running', 'installed' => 'Yes',
            '0', 'false', 'no', 'off', 'disabled', 'stopped', 'not installed' => 'No',
            default => Str::limit((string) $value, 40, ''),
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
