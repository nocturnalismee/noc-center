<?php

namespace Tests\Feature\Ui;

use App\Livewire\Activity\Index;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_viewers_are_forbidden_and_operators_can_read(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin User']);
        $operator = User::factory()->create(['role' => 'operator']);
        $viewer = User::factory()->create();

        $this->event('server.created', $admin, ['server_id' => '01HZZZ'], '2026-11-21 09:00:00');

        $this->get('/activity')->assertRedirect('/login');
        $this->actingAs($viewer)->get('/activity')->assertForbidden();

        $this->actingAs($operator)
            ->get('/activity')
            ->assertOk()
            ->assertSee('Activity')
            ->assertSee('server.created')
            ->assertSee('Admin User');

        $this->actingAs($admin)
            ->get('/activity')
            ->assertOk()
            ->assertSee('server.created');
    }

    public function test_activity_filters_by_action_actor_and_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin User']);
        $operator = User::factory()->create(['role' => 'operator', 'name' => 'Operator User']);

        $this->event('server.created', $admin, ['server_id' => '01AAA'], '2026-11-20 09:00:00');
        $this->event('user.role_changed', $operator, ['user_id' => 9], '2026-11-21 10:00:00');
        $this->event('token.revoked', $admin, ['token_id' => 10], '2026-11-22 11:00:00');

        $this->actingAs($admin);

        Livewire::test(Index::class)
            ->set('action', 'server.created')
            ->assertSee('server_id')
            ->assertDontSee('user_id')
            ->assertDontSee('token_id');

        Livewire::test(Index::class)
            ->set('actorId', (string) $operator->id)
            ->assertSee('user_id')
            ->assertDontSee('server_id')
            ->assertDontSee('token_id');

        Livewire::test(Index::class)
            ->set('from', '2026-11-22')
            ->set('to', '2026-11-22')
            ->assertSee('token_id')
            ->assertDontSee('server_id')
            ->assertDontSee('user_id');
    }

    public function test_activity_targets_are_labeled_without_php_namespaces_and_times_are_wib(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $server = Server::query()->create([
            'name' => 'web-01',
            'hostname' => 'web-01.nusantara.id',
            'interval_seconds' => 60,
            'health' => 'unknown',
            'issue_codes' => [],
            'is_active' => true,
        ]);

        $this->event('server.updated', $admin, ['server_id' => $server->id], '2026-11-22 08:30:00', Server::class, $server->id);

        $this->actingAs($admin)
            ->get('/activity')
            ->assertOk()
            ->assertSee('Server · web-01')
            ->assertDontSee('App\\Models\\Server')
            ->assertSee('WIB');
    }

    public function test_activity_date_filters_use_jakarta_calendar_days(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin User']);

        // 2026-11-22 00:30 in Asia/Jakarta, still 2026-11-21 in UTC.
        $this->event('server.created', $admin, ['server_id' => '01JKT1'], '2026-11-21 17:30:00');
        // 2026-11-21 23:30 in Asia/Jakarta, also 2026-11-21 in UTC.
        $this->event('token.revoked', $admin, ['token_id' => 11], '2026-11-21 16:30:00');

        $this->actingAs($admin);

        Livewire::test(Index::class)
            ->set('from', '2026-11-22')
            ->set('to', '2026-11-22')
            ->assertSee('server_id')
            ->assertDontSee('token_id');
    }

    public function test_the_audit_page_is_read_only_and_has_no_mutation_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->event('server.created', $admin, [], '2026-11-21 09:00:00');

        $this->assertFalse(Route::has('activity.update'));
        $this->assertFalse(Route::has('activity.delete'));
        $this->assertFalse(Route::has('activity.destroy'));

        $this->actingAs($admin);

        $this->delete('/activity')->assertStatus(405);
        $this->put('/activity')->assertStatus(405);

        $this->get('/activity')
            ->assertOk()
            ->assertDontSee('Delete')
            ->assertDontSee('Edit');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function event(
        string $action,
        User $actor,
        array $meta,
        string $createdAt,
        ?string $targetType = null,
        ?string $targetId = null,
    ): AuditEvent {
        return AuditEvent::query()->forceCreate([
            'user_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => '203.0.113.5',
            'user_agent' => 'PHPUnit',
            'meta_json' => $meta === [] ? null : $meta,
            'created_at' => Carbon::parse($createdAt, 'UTC'),
        ]);
    }
}
