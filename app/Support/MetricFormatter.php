<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The single implementation of metric value formatting.
 *
 * Visible output is preserved from the previous per-component helpers; do not
 * introduce a new formatting style here. Locale, timezone and timestamp
 * rendering stay in the views/presenters.
 */
final class MetricFormatter
{
    public static function format(mixed $value, string $format): string
    {
        if ($value === null) {
            return 'Not reported';
        }

        return match ($format) {
            'bool' => self::boolLabel($value),
            'count' => self::count($value),
            'percent' => is_numeric($value) ? self::percent((float) $value, 2) : self::text($value),
            'bytes' => self::bytes($value),
            'seconds' => self::seconds($value),
            'duration' => self::duration($value),
            'load' => is_numeric($value) ? self::load((float) $value) : self::text($value),
            'rate' => is_numeric($value) ? self::rate((float) $value) : self::text($value),
            'uptime' => is_numeric($value) ? self::uptime((int) $value) : self::text($value),
            default => self::text($value),
        };
    }

    public static function percent(?float $value, int $decimals = 0): string
    {
        return $value === null ? '—' : round($value, $decimals).'%';
    }

    public static function bytes(int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = (float) max(0, $value);
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        $decimals = $bytes >= 100 ? 0 : ($bytes >= 10 ? 1 : 2);

        $scaled = number_format($bytes, $decimals, '.', '');

        return rtrim(rtrim($scaled, '0'), '.').' '.$units[$unit];
    }

    public static function gigabytes(int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        $gb = $value / 1024 ** 3;

        return ($gb >= 10 ? number_format($gb, 1) : number_format($gb, 2)).' GB';
    }

    /**
     * @return array{value:string, unit:string}
     */
    public static function rateParts(int|float|null $value): array
    {
        if ($value === null) {
            return ['value' => '—', 'unit' => ''];
        }

        $value = (float) $value;
        $unit = match (true) {
            $value >= 1024 ** 3 => 'GB',
            $value >= 1024 ** 2 => 'MB',
            $value >= 1024 => 'KB',
            default => 'B',
        };
        $scaled = match (true) {
            $value >= 1024 ** 3 => $value / 1024 ** 3,
            $value >= 1024 ** 2 => $value / 1024 ** 2,
            $value >= 1024 => $value / 1024,
            default => $value,
        };

        return [
            'value' => number_format($scaled, $scaled >= 10 ? 1 : 2),
            'unit' => $unit,
        ];
    }

    public static function load(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2);
    }

    public static function uptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days.'d '.$hours.'h';
        }

        if ($hours > 0) {
            return $hours.'h '.$minutes.'m';
        }

        return $minutes.'m';
    }

    /**
     * @param  array{warning:int, critical:int}  $thresholds
     * @return 'muted'|'ok'|'warning'|'critical'
     */
    public static function tone(?float $value, array $thresholds): string
    {
        if ($value === null) {
            return 'muted';
        }

        if ($value >= $thresholds['critical']) {
            return 'critical';
        }

        if ($value >= $thresholds['warning']) {
            return 'warning';
        }

        return 'ok';
    }

    public static function boolLabel(mixed $value): string
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

    public static function text(mixed $value): string
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

    private static function count(mixed $value): string
    {
        if (! is_numeric($value)) {
            return self::text($value);
        }

        $number = (float) $value;

        return abs($number - round($number)) < 0.005
            ? number_format($number, 0, '.', '')
            : self::trim($number);
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
        $hours = intdiv($seconds % 86400, 3600);
        $days = intdiv($seconds, 86400);

        return $days > 0 ? $days.'d '.$hours.'h' : $hours.'h';
    }

    private static function rate(float $value): string
    {
        $parts = self::rateParts($value);

        return $parts['value'].' '.$parts['unit'].'/s';
    }

    private static function trim(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
