<?php

namespace Tests\Unit\Support;

use App\Support\MetricCatalog;
use Tests\TestCase;

class MetricCatalogTest extends TestCase
{
    public function test_server_metrics_are_ordered_with_labels_and_formats(): void
    {
        $this->assertSame([
            'cpu' => ['label' => 'CPU', 'format' => 'percent'],
            'memory' => ['label' => 'Memory', 'format' => 'percent'],
            'disk' => ['label' => 'Disk', 'format' => 'percent'],
            'load' => ['label' => 'Load', 'format' => 'load'],
            'network' => ['label' => 'Network', 'format' => 'rate'],
            'uptime' => ['label' => 'Uptime', 'format' => 'uptime'],
        ], MetricCatalog::serverMetrics());
    }

    public function test_server_metric_returns_a_known_definition(): void
    {
        $this->assertSame(['label' => 'Memory', 'format' => 'percent'], MetricCatalog::serverMetric('memory'));
    }

    public function test_server_metric_falls_back_to_a_headline_label_and_text_format(): void
    {
        $this->assertSame(['label' => 'Net Rx Bps', 'format' => 'text'], MetricCatalog::serverMetric('net_rx_bps'));
    }

    public function test_panel_definition_returns_a_known_definition(): void
    {
        $this->assertSame(
            ['label' => 'Report age', 'type' => 'seconds'],
            MetricCatalog::panelDefinition('smart.cache_age_seconds'),
        );
    }

    public function test_panel_definition_falls_back_to_the_key_label_and_text_type(): void
    {
        $this->assertSame(
            ['label' => 'Mailq total', 'type' => 'text'],
            MetricCatalog::panelDefinition('cpanel.mailq_total'),
        );

        $this->assertSame(
            ['label' => 'Pending sectors', 'type' => 'text'],
            MetricCatalog::panelDefinition('smart.disk.sda.pending'),
        );
    }

    public function test_group_label_returns_known_and_fallback_labels(): void
    {
        $this->assertSame('MySQL', MetricCatalog::groupLabel('mysql'));
        $this->assertSame('Other metrics', MetricCatalog::groupLabel('nope'));
    }

    public function test_field_label_returns_known_fields_and_null_when_unknown(): void
    {
        $this->assertSame('Reallocated sectors', MetricCatalog::fieldLabel('realloc'));
        $this->assertNull(MetricCatalog::fieldLabel('unknown_field'));
    }

    public function test_key_label_uses_field_labels_then_humanises_the_last_segment(): void
    {
        $this->assertSame('Power-on hours', MetricCatalog::keyLabel('smart.disk.sda.power_on_hours'));
        $this->assertSame('Cpu usage pct', MetricCatalog::keyLabel('cpu_usage_pct'));
        $this->assertSame('Foo', MetricCatalog::keyLabel('foo.'));
        $this->assertSame('Value', MetricCatalog::keyLabel('///'));
    }

    public function test_key_label_caps_long_labels_at_eighty_characters(): void
    {
        $label = MetricCatalog::keyLabel(str_repeat('a', 120));

        $this->assertSame(80, mb_strlen($label));
    }

    public function test_supported_formats_are_fixed(): void
    {
        $this->assertSame(
            ['text', 'count', 'percent', 'bytes', 'seconds', 'duration', 'bool', 'load', 'rate', 'uptime'],
            MetricCatalog::supportedFormats(),
        );
    }

    public function test_every_configured_type_is_a_supported_format(): void
    {
        $formats = MetricCatalog::supportedFormats();

        foreach ((array) config('metric_labels.keys', []) as $key => $definition) {
            $this->assertContains($definition['type'], $formats, "Panel key {$key} has an unsupported type.");
        }

        foreach (MetricCatalog::serverMetrics() as $key => $definition) {
            $this->assertContains($definition['format'], $formats, "Server metric {$key} has an unsupported format.");
        }
    }
}
