<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_view_is_available_to_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_an_active_user_can_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_case_insensitive_for_the_email_address(): void
    {
        $user = User::factory()->create([
            'email' => 'mixed.case@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response = $this->post('/login', [
            'email' => 'MIXED.CASE@EXAMPLE.COM',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_returns_a_generic_error_and_leaves_the_user_guest(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_an_inactive_user_cannot_log_in_and_gets_the_same_error(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => 'correct-horse-battery',
        ]);
        $user->forceFill(['is_active' => false])->save();

        $response = $this->from('/login')->post('/login', [
            'email' => 'inactive@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_the_login_limiter_allows_five_attempts_and_rejects_the_sixth_for_the_same_email_and_ip(): void
    {
        User::factory()->create(['email' => 'throttled@example.com']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => 'throttled@example.com',
                'password' => 'wrong-password',
            ])
                ->assertStatus(302)
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);

            $this->assertGuest();
        }

        $this->post('/login', [
            'email' => 'throttled@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_registration_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Public User',
            'email' => 'public@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertNotFound();
    }

    public function test_a_guest_is_redirected_to_login_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_an_authenticated_user_can_view_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_the_login_and_two_factor_rate_limiters_are_registered(): void
    {
        $this->assertNotNull(RateLimiter::limiter('login'));
        $this->assertNotNull(RateLimiter::limiter('two-factor'));
    }
}
