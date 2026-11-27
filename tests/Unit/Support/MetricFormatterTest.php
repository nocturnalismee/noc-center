<?php

namespace Tests\Unit\Support;

use App\Support\MetricFormatter;
use Tests\TestCase;

class MetricFormatterTest extends TestCase
{
    public function test_null_formats_to_not_reported(): void
    {
        $this->assertSame('Not reported', MetricFormatter::format(null, 'percent'));
        $this->assertSame('Not reported', MetricFormatter::format(null, 'text'));
    }

    public function test_percent_defaults_to_whole_numbers(): void
    {
        $this->assertSame('42%', MetricFormatter::percent(42.4));
        $this->assertSame('—', MetricFormatter::percent(null));
    }

    public function test_percent_format_trims_trailing_zeroes_at_two_decimals(): void
    {
        $this->assertSame('12.5%', MetricFormatter::format(12.5, 'percent'));
        $this->assertSame('42%', MetricFormatter::format(42.0, 'percent'));
        $this->assertSame('12.35%', MetricFormatter::format(12.345, 'percent'));
    }

    public function test_count_formats_whole_and_fractional_numbers(): void
    {
        $this->assertSame('42', MetricFormatter::format(42, 'count'));
        $this->assertSame('12.5', MetricFormatter::format(12.5, 'count'));
    }

    public function test_bytes_scale_through_the_units(): void
    {
        $this->assertSame('512 B', MetricFormatter::bytes(512));
        $this->assertSame('1 KB', MetricFormatter::bytes(1024));
        $this->assertSame('1.5 MB', MetricFormatter::bytes((int) (1.5 * 1024 ** 2)));
        $this->assertSame('2 GB', MetricFormatter::bytes((int) (2 * 1024 ** 3)));
        $this->assertSame('2 TB', MetricFormatter::bytes((int) (2 * 1024 ** 4)));
        $this->assertSame('1.5 PB', MetricFormatter::bytes((int) (1.5 * 1024 ** 5)));
    }

    public function test_bytes_decimals_track_magnitude(): void
    {
        $this->assertSame('123 KB', MetricFormatter::bytes(126_000));
        $this->assertSame('12.3 KB', MetricFormatter::bytes(12_600));
        $this->assertSame('1.23 KB', MetricFormatter::bytes(1_260));
    }

    public function test_gigabytes_keeps_two_decimals_below_ten(): void
    {
        $this->assertSame('1.50 GB', MetricFormatter::gigabytes((int) (1.5 * 1024 ** 3)));
        $this->assertSame('12.3 GB', MetricFormatter::gigabytes((int) (12.34 * 1024 ** 3)));
        $this->assertSame('—', MetricFormatter::gigabytes(null));
    }

    public function test_rate_parts_split_value_and_unit(): void
    {
        $this->assertSame(['value' => '4.20', 'unit' => 'MB'], MetricFormatter::rateParts(4.2 * 1024 ** 2));
        $this->assertSame(['value' => '1.00', 'unit' => 'KB'], MetricFormatter::rateParts(1024));
        $this->assertSame(['value' => '512.0', 'unit' => 'B'], MetricFormatter::rateParts(512));
        $this->assertSame(['value' => '12.0', 'unit' => 'MB'], MetricFormatter::rateParts(12 * 1024 ** 2));
        $this->assertSame(['value' => '—', 'unit' => ''], MetricFormatter::rateParts(null));
    }

    public function test_load_formats_to_two_decimals(): void
    {
        $this->assertSame('1.23', MetricFormatter::load(1.234));
        $this->assertSame('—', MetricFormatter::load(null));
    }

    public function test_uptime_uses_days_hours_and_minutes(): void
    {
        $this->assertSame('38d 4h', MetricFormatter::uptime((38 * 86400) + (4 * 3600) + 600));
        $this->assertSame('4h 10m', MetricFormatter::uptime((4 * 3600) + 600));
        $this->assertSame('7m', MetricFormatter::uptime(7 * 60));
    }

    public function test_seconds_and_duration_formats_match_the_panel_output(): void
    {
        $this->assertSame('45s', MetricFormatter::format(45, 'seconds'));
        $this->assertSame('7m', MetricFormatter::format(420, 'seconds'));
        $this->assertSame('4h', MetricFormatter::format(14_400, 'seconds'));
        $this->assertSame('38d', MetricFormatter::format(38 * 86400, 'seconds'));
        $this->assertSame('1d 4h', MetricFormatter::format(100_800, 'duration'));
        $this->assertSame('4h', MetricFormatter::format(14_400, 'duration'));
    }

    public function test_tone_boundaries(): void
    {
        $thresholds = ['warning' => 70, 'critical' => 90];

        $this->assertSame('muted', MetricFormatter::tone(null, $thresholds));
        $this->assertSame('ok', MetricFormatter::tone(69.9, $thresholds));
        $this->assertSame('warning', MetricFormatter::tone(70.0, $thresholds));
        $this->assertSame('warning', MetricFormatter::tone(89.9, $thresholds));
        $this->assertSame('critical', MetricFormatter::tone(90.0, $thresholds));
    }

    public function test_bool_labels(): void
    {
        $this->assertSame('Not reported', MetricFormatter::boolLabel(null));
        $this->assertSame('Yes', MetricFormatter::boolLabel(true));
        $this->assertSame('No', MetricFormatter::boolLabel(false));
        $this->assertSame('Yes', MetricFormatter::boolLabel('enabled'));
        $this->assertSame('No', MetricFormatter::boolLabel('not installed'));
        $this->assertSame('maybe', MetricFormatter::text('maybe'));
    }

    public function test_bool_format_uses_bool_labels(): void
    {
        $this->assertSame('Yes', MetricFormatter::format(1, 'bool'));
        $this->assertSame('No', MetricFormatter::format(0, 'bool'));
    }

    public function test_text_guards_empty_and_non_scalar_values(): void
    {
        $this->assertSame('—', MetricFormatter::text(''));
        $this->assertSame('—', MetricFormatter::text(['a' => 'b']));
        $this->assertSame('value', MetricFormatter::text(' value '));
        $this->assertSame(301, mb_strlen(MetricFormatter::text(str_repeat('a', 400))));
        $this->assertStringEndsWith('…', MetricFormatter::text(str_repeat('a', 400)));
    }

    public function test_unknown_format_falls_back_to_text(): void
    {
        $this->assertSame('hello', MetricFormatter::format('hello', 'nonsense'));
    }
}
