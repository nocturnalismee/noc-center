<?php

namespace Tests\Feature\Ui;

use App\Livewire\Settings\TwoFactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function enableTwoFactor(User $user): void
    {
        app(EnableTwoFactorAuthentication::class)($user);

        $secret = Crypt::decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        app(ConfirmTwoFactorAuthentication::class)($user->fresh(), $code);

        // Fortify remembers recently used TOTP codes in the cache so a code
        // cannot be replayed. Clear it so each test can challenge with the
        // current code after setup.
        Cache::flush();
    }

    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(
            Crypt::decrypt($user->fresh()->two_factor_secret)
        );
    }

    public function test_enabling_requires_confirmation_and_exposes_recovery_codes_only_once(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($user);

        $component = Livewire::test(TwoFactor::class)
            ->assertSee('Two-factor authentication')
            ->assertSee('optional')
            ->call('enable')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
        $this->assertNotSame(
            Crypt::decrypt($user->two_factor_secret),
            $user->two_factor_secret,
            'The TOTP secret must be encrypted at rest.'
        );

        $component
            ->assertSee('<svg', false)
            ->assertSee('aria-label="QR code for the NOC Center authenticator entry"', false)
            ->set('code', '000000')
            ->call('confirm')
            ->assertHasErrors('code');

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());

        $code = $this->currentCode($user);

        $component
            ->set('code', $code)
            ->call('confirm')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        $recoveryCodes = $user->recoveryCodes();

        $this->assertCount(8, $recoveryCodes);

        $component
            ->assertSet('recoveryCodes', $recoveryCodes)
            ->assertSee('Recovery codes remaining')
            ->assertSeeHtml('>8</dd>');

        foreach ($recoveryCodes as $recoveryCode) {
            $component->assertSee($recoveryCode);
        }

        $component->call('$refresh')->assertSet('recoveryCodes', []);

        foreach ($recoveryCodes as $recoveryCode) {
            $component->assertDontSee($recoveryCode);
        }
    }

    public function test_recovery_codes_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();

        $this->enableTwoFactor($user);

        $plainCodes = $user->fresh()->recoveryCodes();
        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

        $this->assertIsString($raw);
        $this->assertNotEmpty($raw);
        $this->assertSame($plainCodes, json_decode(Crypt::decrypt($raw), true));

        foreach ($plainCodes as $plainCode) {
            $this->assertStringNotContainsString($plainCode, $raw);
        }

        $secret = Crypt::decrypt($user->fresh()->two_factor_secret);
        $rawSecret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $rawSecret);
        $this->assertStringNotContainsString($secret, $rawSecret);
    }

    public function test_a_pending_setup_does_not_change_sign_in(): void
    {
        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'password' => 'correct-horse-battery',
        ]);

        app(EnableTwoFactorAuthentication::class)($user);

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        $this->post('/login', [
            'email' => 'pending@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_regenerating_recovery_codes_requires_password_and_invalidates_old_codes(): void
    {
        $user = User::factory()->create([
            'email' => 'regenerate@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->enableTwoFactor($user);

        $oldCodes = $user->fresh()->recoveryCodes();

        $this->actingAs($user);

        $component = Livewire::test(TwoFactor::class)
            ->set('current_password', 'wrong-password')
            ->call('regenerate')
            ->assertHasErrors('current_password');

        $this->assertSame($oldCodes, $user->fresh()->recoveryCodes());

        $component
            ->set('current_password', 'correct-horse-battery')
            ->call('regenerate')
            ->assertHasNoErrors();

        $newCodes = $user->fresh()->recoveryCodes();

        $this->assertCount(8, $newCodes);
        $this->assertNotSame($oldCodes, $newCodes);
        $component->assertSet('recoveryCodes', $newCodes);

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'regenerate@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => $oldCodes[0],
        ])->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors('recovery_code');

        $this->assertGuest();

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => $newCodes[0],
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_disabling_requires_the_current_password_and_restores_password_only_sign_in(): void
    {
        $user = User::factory()->create([
            'email' => 'two-factor@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->enableTwoFactor($user);

        $this->actingAs($user);

        $component = Livewire::test(TwoFactor::class)
            ->assertSee('Enabled')
            ->assertSee('Recovery codes');

        $component
            ->set('current_password', 'wrong-password')
            ->call('disable')
            ->assertHasErrors('current_password');

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertNotNull($user->fresh()->two_factor_secret);

        $component
            ->set('current_password', 'correct-horse-battery')
            ->call('disable')
            ->assertHasNoErrors()
            ->assertSet('recoveryCodes', []);

        $user->refresh();

        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'two-factor@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_without_two_factor_signs_in_normally_without_being_forced_to_enroll(): void
    {
        $user = User::factory()->create([
            'email' => 'password-only@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response = $this->post('/login', [
            'email' => 'password-only@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertRedirect('/dashboard');
        $response->assertSessionMissing('login.id');

        $this->assertAuthenticatedAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('two-factor-challenge', false)
            ->assertDontSee('settings/security', false);
    }

    public function test_an_active_two_factor_user_must_complete_the_challenge_with_a_totp(): void
    {
        $user = User::factory()->create([
            'email' => 'totp@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->enableTwoFactor($user);

        $this->post('/login', [
            'email' => 'totp@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));

        $this->post(route('two-factor.login.store'), [
            'code' => $this->currentCode($user),
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_recovery_code_completes_login_once_and_cannot_be_reused(): void
    {
        $user = User::factory()->create([
            'email' => 'recovery@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->enableTwoFactor($user);

        $codes = $user->fresh()->recoveryCodes();

        $this->post('/login', [
            'email' => 'recovery@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => $codes[0],
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'recovery@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => $codes[0],
        ])->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors('recovery_code');

        $this->assertGuest();

        // The remaining recovery codes are still valid after a code is used.
        $this->post(route('two-factor.login.store'), [
            'recovery_code' => $codes[1],
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_enrollment_is_scoped_to_the_signed_in_user(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $other = User::factory()->create(['role' => 'admin']);

        $this->actingAs($viewer);

        $component = Livewire::test(TwoFactor::class)
            ->call('enable')
            ->assertHasNoErrors();

        $this->assertNotNull($viewer->fresh()->two_factor_secret);
        $this->assertNull($viewer->fresh()->two_factor_confirmed_at);
        $this->assertNull($other->fresh()->two_factor_secret);
        $this->assertNull($other->fresh()->two_factor_confirmed_at);
        $this->assertFalse($other->fresh()->hasEnabledTwoFactorAuthentication());

        $component
            ->set('code', $this->currentCode($viewer))
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertTrue($viewer->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertFalse($other->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_no_role_is_forced_into_two_factor(): void
    {
        foreach (['viewer' => 'viewer@example.com', 'operator' => 'operator@example.com', 'admin' => 'admin@example.com'] as $role => $email) {
            $user = User::factory()->create([
                'role' => $role,
                'email' => $email,
                'password' => 'correct-horse-battery',
            ]);

            $this->assertNull($user->two_factor_secret);
            $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

            $this->post('/login', [
                'email' => $email,
                'password' => 'correct-horse-battery',
            ])->assertRedirect('/dashboard');

            $this->assertAuthenticatedAs($user);

            $this->post('/logout');
        }
    }

    public function test_the_challenge_page_uses_the_guest_layout_and_the_security_page_requires_auth(): void
    {
        $user = User::factory()->create([
            'email' => 'challenge@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->enableTwoFactor($user);

        $this->post('/login', [
            'email' => 'challenge@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('two-factor.login'));

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSee('guest-shell', false)
            ->assertSee('action="'.route('two-factor.login.store').'"', false)
            ->assertSee('name="code"', false)
            ->assertSee('autocomplete="one-time-code"', false)
            ->assertSee('name="recovery_code"', false);

        $this->post('/logout');

        $this->get('/settings/security')->assertRedirect('/login');

        $this->actingAs($user)
            ->get('/settings/security')
            ->assertOk()
            ->assertSee('Two-factor authentication')
            ->assertSee('optional')
            ->assertSee('aria-label="Settings sections"', false);
    }
}
