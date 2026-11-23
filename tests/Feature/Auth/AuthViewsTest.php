<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_view_the_forgot_password_page(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Reset your password')
            ->assertSee(route('password.email'), false);
    }

    public function test_a_guest_can_view_the_reset_password_page_with_a_token(): void
    {
        $this->get('/reset-password/dummy-token')
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('dummy-token')
            ->assertSee(route('password.update'), false);
    }

    public function test_a_guest_is_redirected_to_login_from_the_confirm_password_page(): void
    {
        $this->get('/user/confirm-password')->assertRedirect('/login');
    }

    public function test_an_authenticated_user_can_view_the_confirm_password_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/user/confirm-password')
            ->assertOk()
            ->assertSee('Confirm your password')
            ->assertSee(route('password.confirm.store'), false);
    }

    public function test_the_login_page_links_to_the_registered_forgot_password_route(): void
    {
        $this->assertTrue(Route::has('password.request'));

        $this->get('/login')
            ->assertOk()
            ->assertSee(route('password.request'), false);
    }
}
