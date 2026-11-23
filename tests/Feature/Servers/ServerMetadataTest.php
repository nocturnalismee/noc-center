<?php

namespace Tests\Feature\Servers;

use App\Models\Server;
use App\Services\Servers\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerMetadataTest extends TestCase
{
    use RefreshDatabase;

    private ServerService $servers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servers = $this->app->make(ServerService::class);
    }

    public function test_group_name_is_renamed_to_server_type(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'server_type' => 'web',
        ]);

        $columns = Schema::getColumnListing('servers');

        $this->assertContains('server_type', $columns);
        $this->assertNotContains('group_name', $columns);
        $this->assertSame('web', $server->server_type);
        $this->assertSame('web', DB::table('servers')->where('id', $server->id)->value('server_type'));
    }

    public function test_metadata_defaults_to_null_with_alerts_enabled(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
        ]);

        $this->assertNull($server->server_type);
        $this->assertNull($server->provider);
        $this->assertNull($server->location);
        $this->assertNull($server->ip_address);
        $this->assertNull($server->label);
        $this->assertTrue($server->alerts_enabled);
        $this->assertTrue($server->fresh()->alerts_enabled);
    }

    public function test_database_defaults_alerts_enabled_to_true_without_the_attribute(): void
    {
        DB::table('servers')->insert([
            'id' => (string) Str::ulid(),
            'name' => 'raw-01',
            'hostname' => 'raw-01.example.test',
            'panel' => 'none',
            'interval_seconds' => 60,
            'health' => 'unknown',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            1,
            (int) DB::table('servers')->where('name', 'raw-01')->value('alerts_enabled'),
        );

        $server = Server::query()->where('name', 'raw-01')->sole();

        $this->assertTrue($server->alerts_enabled);
        $this->assertIsBool($server->alerts_enabled);
    }

    public function test_create_persists_every_metadata_field(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'server_type' => 'shared hosting',
            'provider' => 'Contabo',
            'location' => 'Jakarta',
            'ip_address' => '203.0.113.10',
            'label' => 'JHD-001',
            'alerts_enabled' => false,
        ]);

        $fresh = $server->fresh();

        $this->assertSame('shared hosting', $fresh->server_type);
        $this->assertSame('Contabo', $fresh->provider);
        $this->assertSame('Jakarta', $fresh->location);
        $this->assertSame('203.0.113.10', $fresh->ip_address);
        $this->assertSame('JHD-001', $fresh->label);
        $this->assertFalse($fresh->alerts_enabled);
        $this->assertSame(0, (int) DB::table('servers')->where('id', $server->id)->value('alerts_enabled'));
    }

    public function test_create_trims_metadata(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'server_type' => '  web  ',
            'provider' => '  Contabo ',
            'location' => ' Jakarta  ',
            'ip_address' => ' 203.0.113.10 ',
            'label' => ' JHD-001  ',
        ]);

        $this->assertSame('web', $server->server_type);
        $this->assertSame('Contabo', $server->provider);
        $this->assertSame('Jakarta', $server->location);
        $this->assertSame('203.0.113.10', $server->ip_address);
        $this->assertSame('JHD-001', $server->label);
    }

    public function test_blank_and_null_metadata_are_stored_as_null(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'server_type' => '   ',
            'provider' => null,
            'location' => '',
            'ip_address' => '  ',
            'label' => null,
        ]);

        $fresh = $server->fresh();

        $this->assertNull($fresh->server_type);
        $this->assertNull($fresh->provider);
        $this->assertNull($fresh->location);
        $this->assertNull($fresh->ip_address);
        $this->assertNull($fresh->label);

        $row = DB::table('servers')->where('id', $server->id)->first();
        $this->assertNull($row->server_type);
        $this->assertNull($row->provider);
        $this->assertNull($row->location);
        $this->assertNull($row->ip_address);
        $this->assertNull($row->label);
    }

    public function test_metadata_is_never_unique(): void
    {
        $first = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'server_type' => 'web',
            'provider' => 'Contabo',
            'location' => 'Jakarta',
            'ip_address' => '203.0.113.10',
            'label' => 'JHD-001',
        ]);

        $second = $this->servers->create([
            'name' => 'web-02',
            'hostname' => 'web-02.example.test',
            'server_type' => 'web',
            'provider' => 'Contabo',
            'location' => 'Jakarta',
            'ip_address' => '203.0.113.10',
            'label' => 'JHD-001',
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('JHD-001', $second->fresh()->label);
    }

    #[DataProvider('validIpProvider')]
    public function test_ip_address_accepts_ipv4_and_ipv6(string $ip): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'ip_address' => $ip,
        ]);

        $this->assertSame($ip, $server->fresh()->ip_address);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validIpProvider(): array
    {
        return [
            'ipv4' => ['192.0.2.10'],
            'ipv6' => ['2001:db8::1'],
            'ipv6 loopback' => ['::1'],
        ];
    }

    #[DataProvider('invalidIpProvider')]
    public function test_ip_address_rejects_malformed_values(string $ip): void
    {
        try {
            $this->servers->create([
                'name' => 'web-01',
                'hostname' => 'web-01.example.test',
                'ip_address' => $ip,
            ]);

            $this->fail('An invalid IP address must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ip_address', $exception->errors());
        }

        $this->assertSame(0, Server::query()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidIpProvider(): array
    {
        return [
            'words' => ['not-an-ip'],
            'out of range octet' => ['999.1.1.1'],
            'too few octets' => ['1.2.3'],
        ];
    }

    #[DataProvider('overlongMetadataProvider')]
    public function test_metadata_lengths_are_capped(string $attribute, string $overlong, string $atLimit): void
    {
        try {
            $this->servers->create([
                'name' => 'web-01',
                'hostname' => 'web-01.example.test',
                $attribute => $overlong,
            ]);

            $this->fail("Overlength {$attribute} must not be accepted.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($attribute, $exception->errors());
        }

        $this->assertSame(0, Server::query()->count());

        $accepted = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            $attribute => $atLimit,
        ]);

        $this->assertSame($atLimit, $accepted->fresh()->{$attribute});
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function overlongMetadataProvider(): array
    {
        return [
            'server_type' => ['server_type', Str::repeat('a', 101), Str::repeat('a', 100)],
            'provider' => ['provider', Str::repeat('a', 101), Str::repeat('a', 100)],
            'location' => ['location', Str::repeat('a', 101), Str::repeat('a', 100)],
            // 45 is the widest textual IPv6 address, so the at-limit case is valid.
            'ip_address' => ['ip_address', Str::repeat('1', 46), '2001:0db8:85a3:0000:0000:8a2e:0370:7334'],
            'label' => ['label', Str::repeat('a', 101), Str::repeat('a', 100)],
        ];
    }

    #[DataProvider('nonBooleanAlertsProvider')]
    public function test_alerts_enabled_must_be_boolean(mixed $value): void
    {
        try {
            $this->servers->create([
                'name' => 'web-01',
                'hostname' => 'web-01.example.test',
                'alerts_enabled' => $value,
            ]);

            $this->fail('A non-boolean alerts_enabled must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('alerts_enabled', $exception->errors());
        }

        $this->assertSame(0, Server::query()->count());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonBooleanAlertsProvider(): array
    {
        return [
            'string yes' => ['yes'],
            'integer two' => [2],
            'empty array' => [[]],
        ];
    }

    public function test_server_type_is_mass_assignable_and_serialises(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'server_type' => 'vps',
            'provider' => 'Contabo',
            'location' => 'Jakarta',
            'ip_address' => '192.0.2.10',
            'label' => 'JHD-001',
        ]);

        $direct = Server::create([
            'name' => 'web-02',
            'hostname' => 'web-02.example.test',
            'server_type' => 'db',
        ]);

        $this->assertSame('db', $direct->fresh()->server_type);

        $array = $server->toArray();

        $this->assertSame('vps', $array['server_type']);
        $this->assertSame('Contabo', $array['provider']);
        $this->assertSame('Jakarta', $array['location']);
        $this->assertSame('192.0.2.10', $array['ip_address']);
        $this->assertSame('JHD-001', $array['label']);
        $this->assertTrue($array['alerts_enabled']);
        $this->assertArrayNotHasKey('group_name', $array);
    }

    public function test_group_name_is_ignored_by_create(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'group_name' => 'legacy-group',
        ]);

        $this->assertNull($server->fresh()->server_type);
        $this->assertArrayNotHasKey('group_name', $server->fresh()->toArray());
    }
}
