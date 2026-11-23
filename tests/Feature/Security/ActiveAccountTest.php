<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deactivated_user_is_logged_out_and_redirected_from_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $remember = $admin->remember_token;

        $this->actingAs($admin);

        $admin->forceFill(['is_active' => false])->save();

        $this->get('/settings/users')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNotSame($remember, $admin->fresh()->remember_token);
    }

    public function test_a_deactivated_admin_is_blocked_from_alert_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $admin->forceFill(['is_active' => false])->save();

        $this->get('/settings/alerts')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_deactivated_user_gets_a_json_403_on_a_livewire_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $admin->forceFill(['is_active' => false])->save();

        $this->postJson(route('default-livewire.update'))
            ->assertStatus(403)
            ->assertJson(['message' => 'This account is inactive.']);

        $this->assertGuest();
    }

    public function test_an_active_user_can_still_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->post('/login', [
            'email' => 'active@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }
}
