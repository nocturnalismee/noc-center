<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The single vocabulary for metric keys: panel definitions, server metrics,
 * group and field labels. `config/metric_labels.php` stays the data file.
 */
final class MetricCatalog
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_FORMATS = [
        'text',
        'count',
        'percent',
        'bytes',
        'seconds',
        'duration',
        'bool',
        'load',
        'rate',
        'uptime',
    ];

    /**
     * @return array<string, array{label:string, format:string}>
     */
    public static function serverMetrics(): array
    {
        /** @var array<string, array{label:string, format:string}> $metrics */
        $metrics = config('metric_labels.server', []);

        return $metrics;
    }

    /**
     * @return array{label:string, format:string}
     */
    public static function serverMetric(string $key): array
    {
        $metric = self::serverMetrics()[$key] ?? null;

        if (is_array($metric) && isset($metric['label'], $metric['format'])) {
            return [
                'label' => (string) $metric['label'],
                'format' => (string) $metric['format'],
            ];
        }

        return ['label' => Str::headline($key), 'format' => 'text'];
    }

    /**
     * @return array{label:string, type:string}
     */
    public static function panelDefinition(string $key): array
    {
        $keys = (array) config('metric_labels.keys', []);
        $definition = $keys[$key] ?? null;
        $label = is_array($definition) ? (string) ($definition['label'] ?? '') : '';

        return [
            'label' => $label !== '' ? $label : self::keyLabel($key),
            'type' => is_array($definition) ? (string) ($definition['type'] ?? 'text') : 'text',
        ];
    }

    public static function groupLabel(string $prefix): string
    {
        $groups = (array) config('metric_labels.groups', []);
        $label = $groups[$prefix] ?? null;

        return is_string($label) && $label !== '' ? $label : 'Other metrics';
    }

    public static function fieldLabel(string $field): ?string
    {
        $fields = (array) config('metric_labels.fields', []);
        $label = $fields[$field] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    public static function keyLabel(string $key): string
    {
        $segments = explode('.', $key);
        $last = end($segments) ?: $key;

        $known = self::fieldLabel($last);

        if ($known !== null) {
            return $known;
        }

        $label = preg_replace('/[^A-Za-z0-9]+/', ' ', $last) ?? $last;
        $label = trim($label);

        return $label === '' ? 'Value' : Str::limit(ucfirst(strtolower($label)), 80, '');
    }

    /**
     * @return list<string>
     */
    public static function supportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }
}
