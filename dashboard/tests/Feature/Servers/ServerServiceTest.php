<?php

namespace Tests\Feature\Servers;

use App\Enums\ServerHealth;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServerService $servers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servers = $this->app->make(ServerService::class);
    }

    public function test_create_persists_the_name_hostname_and_defaults(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
        ]);

        $this->assertInstanceOf(Server::class, $server);
        $this->assertTrue($server->exists);
        $this->assertSame('web-01', $server->name);
        $this->assertSame('web-01.example.test', $server->hostname);

        $this->assertSame(60, $server->interval_seconds);
        $this->assertSame('none', $server->panel);
        $this->assertNull($server->group_name);
        $this->assertNull($server->notes);
        $this->assertTrue($server->is_active);
        $this->assertSame(ServerHealth::Unknown, $server->health);
        $this->assertSame([], $server->issue_codes);
        $this->assertNull($server->last_seen_at);
        $this->assertNull($server->stale_after);
        $this->assertNull($server->offline_after);
    }

    public function test_create_persists_explicit_optional_attributes(): void
    {
        $server = $this->servers->create([
            'name' => 'db-01',
            'hostname' => 'db-01.example.test',
            'panel' => 'cpanel',
            'group_name' => 'databases',
            'notes' => 'Primary database node.',
            'interval_seconds' => 300,
        ]);

        $this->assertSame('cpanel', $server->panel);
        $this->assertSame('databases', $server->group_name);
        $this->assertSame('Primary database node.', $server->notes);
        $this->assertSame(300, $server->interval_seconds);
    }

    #[DataProvider('invalidIntervalProvider')]
    public function test_interval_seconds_must_be_between_ten_and_three_thousand_six_hundred(mixed $interval): void
    {
        $this->expectException(ValidationException::class);

        $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'interval_seconds' => $interval,
        ]);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidIntervalProvider(): array
    {
        return [
            'below minimum' => [9],
            'above maximum' => [3601],
            'zero' => [0],
            'negative' => [-1],
            'not an integer' => ['sixty'],
            'fractional' => [10.5],
        ];
    }

    public function test_name_must_be_unique(): void
    {
        $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
        ]);

        $this->expectException(ValidationException::class);

        $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-02.example.test',
        ]);
    }

    public function test_name_is_required(): void
    {
        $this->expectException(ValidationException::class);

        $this->servers->create([
            'hostname' => 'web-01.example.test',
        ]);
    }

    #[DataProvider('blankNameProvider')]
    public function test_name_cannot_be_blank(string $name): void
    {
        $this->expectException(ValidationException::class);

        $this->servers->create([
            'name' => $name,
            'hostname' => 'web-01.example.test',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blankNameProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }

    public function test_create_trims_name_and_hostname(): void
    {
        $server = $this->servers->create([
            'name' => '  web-01  ',
            'hostname' => '  web-01.example.test  ',
        ]);

        $this->assertSame('web-01', $server->name);
        $this->assertSame('web-01.example.test', $server->hostname);
    }

    public function test_an_empty_panel_defaults_to_none(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'panel' => '',
        ]);

        $this->assertSame('none', $server->panel);
    }

    #[DataProvider('overlongAttributeProvider')]
    public function test_optional_text_attributes_are_length_limited(string $attribute, string $value): void
    {
        $this->expectException(ValidationException::class);

        $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            $attribute => $value,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function overlongAttributeProvider(): array
    {
        return [
            'hostname' => ['hostname', Str::repeat('a', 256)],
            'panel' => ['panel', Str::repeat('a', 33)],
            'group_name' => ['group_name', Str::repeat('a', 101)],
            'notes' => ['notes', Str::repeat('a', 2001)],
        ];
    }

    public function test_create_forces_active_state_and_ignores_mass_assignment(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
            'is_active' => false,
            'health' => ServerHealth::Critical,
            'issue_codes' => ['disk'],
        ]);

        $this->assertTrue($server->is_active);
        $this->assertSame(ServerHealth::Unknown, $server->health);
        $this->assertSame([], $server->issue_codes);
    }

    public function test_a_soft_deleted_server_still_reserves_its_name(): void
    {
        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
        ]);

        $server->delete();

        $this->assertSoftDeleted($server);

        $this->expectException(ValidationException::class);

        $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-02.example.test',
        ]);
    }

    public function test_hostname_is_required(): void
    {
        $this->expectException(ValidationException::class);

        $this->servers->create([
            'name' => 'web-01',
        ]);
    }

    public function test_create_writes_a_server_created_audit_event_for_the_actor(): void
    {
        $actor = User::factory()->create();

        $server = $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
        ], $actor);

        $event = AuditEvent::query()->where('action', 'server.created')->sole();

        $this->assertSame($actor->id, $event->user_id);
        $this->assertSame($server->getMorphClass(), $event->target_type);
        $this->assertSame((string) $server->getKey(), $event->target_id);
        $this->assertSame(['server_id' => $server->getKey()], $event->meta_json);
    }

    public function test_create_without_an_actor_writes_a_null_actor(): void
    {
        $this->servers->create([
            'name' => 'web-01',
            'hostname' => 'web-01.example.test',
        ]);

        $event = AuditEvent::query()->where('action', 'server.created')->sole();

        $this->assertNull($event->user_id);
    }

    public function test_name_is_capped_at_one_hundred_characters(): void
    {
        $this->expectException(ValidationException::class);

        $this->servers->create([
            'name' => Str::repeat('a', 101),
            'hostname' => 'web-01.example.test',
        ]);
    }
}
