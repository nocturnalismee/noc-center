<?php

namespace Tests\Unit\Servers;

use App\Models\MetricSample;
use App\Models\Server;
use App\Servers\ServerDetailPresenter;
use App\Support\MetricFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServerDetailPresenterTest extends TestCase
{
    use RefreshDatabase;

    private function server(array $overrides = []): Server
    {
        return Server::query()->create(array_merge([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'ip_address' => '10.0.0.5',
            'label' => 'Web primary',
            'server_type' => 'vps',
            'provider' => 'hetzner',
            'location' => 'nbg1',
            'panel' => 'cpanel',
            'agent_version' => '1.4.2',
            'interval_seconds' => 30,
            'os_name' => 'Ubuntu',
            'os_version' => '24.04',
            'kernel' => '6.8.0-generic',
            'health' => 'ok',
            'issue_codes' => ['cpu_high', 'server_offline', 'custom_code', 'cpu_high'],
            'is_active' => true,
            'last_seen_at' => Carbon::parse('2026-05-01 02:00:00', 'UTC'),
            'stale_after' => Carbon::parse('2026-05-01 03:09:00', 'UTC'),
            'offline_after' => Carbon::parse('2026-05-01 03:14:00', 'UTC'),
        ], $overrides));
    }

    private function sample(Server $server): MetricSample
    {
        return MetricSample::query()->create([
            'server_id' => $server->getKey(),
            'collected_at' => Carbon::parse('2026-05-01 03:04:05', 'UTC'),
            'received_at' => Carbon::parse('2026-05-01 03:04:06', 'UTC'),
            'interval_seconds' => 30,
            'cpu_usage_pct' => 42.4,
            'cpu_iowait_pct' => 1.6,
            'cpu_cores' => 8,
            'load1' => 1.234,
            'load5' => 1.5,
            'load15' => 2.0,
            'mem_total_bytes' => 8 * 1024 ** 3,
            'mem_used_bytes' => 4 * 1024 ** 3,
            'mem_used_pct' => 50.0,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0,
            'disk_total_bytes' => 100 * 1024 ** 3,
            'disk_used_bytes' => 50 * 1024 ** 3,
            'disk_used_pct' => 50.0,
            'disk_inode_pct' => 10.0,
            'net_rx_bps' => (int) (4.2 * 1024 ** 2),
            'net_tx_bps' => 1024,
            'processes_running' => 120,
            'processes_blocked' => 2,
            'uptime_seconds' => (38 * 86400) + (4 * 3600) + 600,
            'payload_bytes' => 1024,
        ]);
    }

    private function presenter(Server $server): ServerDetailPresenter
    {
        return new ServerDetailPresenter($server);
    }

    public function test_metric_strip_without_a_sample(): void
    {
        $metrics = $this->presenter($this->server())->metrics(null);

        $this->assertSame(['CPU', 'Memory', 'Disk', 'Load', 'Network', 'Uptime'], array_column($metrics, 'label'));
        $this->assertSame(['—', '—', '—', '—', '—', '—'], array_column($metrics, 'value'));
        $this->assertSame(array_fill(0, 6, 'No sample yet'), array_column($metrics, 'meta'));
    }

    public function test_metric_strip_with_a_sample(): void
    {
        $server = $this->server();
        $sample = $this->sample($server);

        $this->assertSame([
            ['label' => 'CPU', 'value' => '42%', 'meta' => '8 cores · iowait 2%'],
            ['label' => 'Memory', 'value' => '50%', 'meta' => '4.00 GB / 8.00 GB'],
            ['label' => 'Disk', 'value' => '50%', 'meta' => '50.0 GB / 100.0 GB'],
            ['label' => 'Load', 'value' => '1.23', 'meta' => '5m 1.50 · 15m 2.00'],
            ['label' => 'Network', 'value' => '4.20 / 1.00', 'meta' => 'MB/s in · KB/s out'],
            ['label' => 'Uptime', 'value' => '38d 4h', 'meta' => 'Since 24 Mar 2026'],
        ], $this->presenter($server)->metrics($sample));
    }

    public function test_overview_charts(): void
    {
        $server = $this->server();
        $sample = $this->sample($server);

        $charts = $this->presenter($server)->charts('overview', '1h', $sample);

        $this->assertSame(['resources', 'load-network'], array_keys($charts));

        $resources = $charts['resources'];
        $this->assertSame('Resource summary', $resources['title']);
        $this->assertSame('Last hour · 30s collector samples · WIB', $resources['subtitle']);
        $this->assertSame('percent', $resources['unit']);
        $this->assertSame('Current CPU 42%, memory 50%, disk 50%. Last hour.', $resources['summary']);
        $this->assertSame(['cpu', 'memory', 'disk'], array_column($resources['series'], 'metric'));
        $this->assertSame(
            route('servers.series', ['server' => $server, 'metric' => 'cpu', 'range' => '1h']),
            $resources['series'][0]['url'],
        );

        $load = $charts['load-network'];
        $this->assertSame('mixed', $load['unit']);
        $this->assertSame(['y', 'y1', 'y1'], array_column($load['series'], 'axis'));
        $this->assertSame(['load', 'rate', 'rate'], array_map(
            fn (array $series): ?string => $series['format'] ?? null,
            $load['series'],
        ));
        $this->assertSame(
            'Current load 1.23 · 4.20 MB/s in · 1.00 KB/s out.',
            $load['summary'],
        );
    }

    public function test_performance_charts(): void
    {
        $server = $this->server();
        $sample = $this->sample($server);

        $charts = $this->presenter($server)->charts('performance', '24h', $sample);

        $this->assertSame(['cpu', 'memory', 'disk', 'load', 'network'], array_keys($charts));
        $this->assertSame('30s collector · percent · WIB', $charts['cpu']['subtitle']);
        $this->assertSame('bytes', $charts['memory']['unit']);
        $this->assertSame('Current memory 4 GB of 8 GB.', $charts['memory']['summary']);
        $this->assertSame(
            'Current disk '.MetricFormatter::bytes($sample->disk_used_bytes).' of '.MetricFormatter::bytes($sample->disk_total_bytes).'.',
            $charts['disk']['summary'],
        );
        $this->assertSame('Current load 1.23 · 5m 1.50 · 15m 2.00.', $charts['load']['summary']);
        $this->assertSame('Current 4.20 MB/s in · 1.00 KB/s out.', $charts['network']['summary']);
        $this->assertSame('load', $charts['load']['unit']);
        $this->assertSame('rate', $charts['network']['unit']);
        $this->assertSame(
            route('servers.series', ['server' => $server, 'metric' => 'memory_bytes', 'range' => '24h']),
            $charts['memory']['series'][0]['url'],
        );
    }

    public function test_charts_without_a_sample_summarise_as_empty(): void
    {
        $charts = $this->presenter($this->server())->charts('overview', '1h', null);

        foreach ($charts as $chart) {
            $this->assertSame('No samples recorded yet.', $chart['summary']);
        }
    }

    public function test_itsm_facts(): void
    {
        $server = $this->server();
        $sample = $this->sample($server);

        $this->assertSame([
            'Hostname' => 'web-01.example.test',
            'Primary IP' => '10.0.0.5',
            'Asset label' => 'Web primary',
            'Type' => 'vps',
            'Provider' => 'hetzner',
            'Location' => 'nbg1',
            'Panel' => 'cpanel',
            'Agent' => 'v1.4.2',
            'Interval' => '30s',
            'Last sample' => $sample->collected_at->diffForHumans(short: true),
            'OS' => 'Ubuntu 24.04',
            'Kernel' => '6.8.0-generic',
        ], $this->presenter($server)->itsm($sample));
    }

    public function test_itsm_fallbacks_without_a_sample(): void
    {
        $server = $this->server([
            'ip_address' => null,
            'label' => null,
            'server_type' => null,
            'provider' => null,
            'location' => null,
            'panel' => '',
            'agent_version' => null,
            'os_name' => '',
            'os_version' => null,
            'kernel' => null,
        ]);

        $itsm = $this->presenter($server)->itsm(null);

        $this->assertSame('—', $itsm['Primary IP']);
        $this->assertSame('—', $itsm['Agent']);
        $this->assertSame('—', $itsm['OS']);
        $this->assertSame('No sample yet', $itsm['Last sample']);
    }

    public function test_last_seen(): void
    {
        $this->travelTo(Carbon::parse('2026-05-01 03:10:00', 'UTC'));

        $server = $this->server(['last_seen_at' => Carbon::parse('2026-05-01 02:00:00', 'UTC')]);

        $this->assertSame('Last seen 1h ago · 09:00:00 WIB', $this->presenter($server)->lastSeen());
        $this->assertSame('Never seen', $this->presenter($this->server([
            'name' => 'new-01',
            'hostname' => 'new-01.example.test',
            'last_seen_at' => null,
        ]))->lastSeen());
    }

    public function test_issue_labels_use_the_long_form_and_deduplicate(): void
    {
        $this->assertSame(
            ['CPU high', 'Server offline', 'Custom Code'],
            $this->presenter($this->server())->issueLabels(),
        );
    }
}
