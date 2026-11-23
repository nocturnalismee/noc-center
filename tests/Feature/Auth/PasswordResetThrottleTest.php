<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Fortify's password-reset endpoints share a 3/minute per-IP limiter.
 *
 * Both the request-link and the reset-submit route must be throttled: an
 * unauthenticated attacker must not be able to enumerate addresses or burn
 * reset tokens by replaying the form.
 */
class PasswordResetThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        RateLimiter::clear('passwords:127.0.0.1');
    }

    public function test_the_password_email_route_allows_three_attempts_then_returns_429(): void
    {
        User::factory()->create(['email' => 'throttle-reset@example.com']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('password.email'), ['email' => 'throttle-reset@example.com'])
                ->assertStatus(302);
        }

        $this->post(route('password.email'), ['email' => 'throttle-reset@example.com'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_the_password_update_route_allows_three_attempts_then_returns_429(): void
    {
        User::factory()->create(['email' => 'throttle-update@example.com']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('password.update'), [
                'token' => 'not-a-real-token',
                'email' => 'throttle-update@example.com',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertStatus(302);
        }

        $this->post(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => 'throttle-update@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_the_password_limiter_is_registered(): void
    {
        $this->assertNotNull(RateLimiter::limiter('passwords'));
    }
}
