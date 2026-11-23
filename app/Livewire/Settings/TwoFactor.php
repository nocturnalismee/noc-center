<?php

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Optional TOTP two-factor authentication for the signed-in user (R53).
 *
 * Every role may enable or disable 2FA from this page. Nothing here forces
 * enrollment: while 2FA is off the account keeps signing in with its password
 * only. Fortify owns the TOTP lifecycle and the encrypted storage of the
 * secret and recovery codes; this component only drives its actions.
 */
#[Layout('layouts.app')]
class TwoFactor extends Component
{
    public string $code = '';

    public string $current_password = '';

    /**
     * Recovery codes are exposed exactly once after confirmation or
     * regeneration. They are not persisted in the component state after the
     * next request because hydrate() clears them.
     *
     * @var array<int, string>
     */
    public array $recoveryCodes = [];

    public ?string $statusMessage = null;

    /**
     * Which password-protected action produced the current
     * `current_password` error, so only that form shows it.
     */
    public ?string $passwordAction = null;

    public function hydrate(): void
    {
        $this->recoveryCodes = [];
    }

    public function enable(EnableTwoFactorAuthentication $action): void
    {
        $this->resetValidation();
        $this->statusMessage = null;
        $this->passwordAction = 'enable';

        $user = auth()->user();

        try {
            if (! $this->confirmCurrentPassword($user->password)) {
                return;
            }

            $action($user);

            $this->code = '';
            $this->passwordAction = null;
            $this->statusMessage = 'Scan the QR code with an authenticator app, then enter the 6-digit code to finish setup.';
        } finally {
            $this->current_password = '';
        }
    }

    public function confirm(ConfirmTwoFactorAuthentication $action): void
    {
        $this->resetValidation();
        $this->statusMessage = null;
        $this->passwordAction = null;

        $this->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $action(auth()->user(), $this->code);
        } catch (ValidationException $exception) {
            $this->addError(
                'code',
                $exception->validator->errors()->first('code') ?: 'The provided two-factor authentication code was invalid.'
            );

            return;
        }

        $user = auth()->user()->fresh();

        $this->recoveryCodes = $user->recoveryCodes();
        $this->code = '';
        $this->statusMessage = 'Two-factor authentication is enabled. Store the recovery codes below somewhere safe.';
    }

    public function regenerate(GenerateNewRecoveryCodes $action): void
    {
        $this->resetValidation();
        $this->statusMessage = null;
        $this->passwordAction = 'regenerate';

        $user = auth()->user();

        try {
            if (! $this->confirmCurrentPassword($user->password)) {
                return;
            }

            $action($user);

            $user->refresh();

            $this->recoveryCodes = $user->recoveryCodes();
            $this->passwordAction = null;
            $this->statusMessage = 'New recovery codes generated. Previous codes no longer work.';
        } finally {
            $this->current_password = '';
        }
    }

    public function disable(DisableTwoFactorAuthentication $action): void
    {
        $this->resetValidation();
        $this->statusMessage = null;
        $this->passwordAction = 'disable';

        $user = auth()->user();

        try {
            if (! $this->confirmCurrentPassword($user->password)) {
                return;
            }

            $action($user);

            $this->recoveryCodes = [];
            $this->code = '';
            $this->passwordAction = null;
            $this->statusMessage = 'Two-factor authentication is disabled. Password-only sign-in is active again.';
        } finally {
            $this->current_password = '';
        }
    }

    private function confirmCurrentPassword(string $hash): bool
    {
        $this->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($this->current_password, $hash)) {
            $this->addError('current_password', 'The password is incorrect.');

            return false;
        }

        return true;
    }

    public function render(): View
    {
        $user = auth()->user()->fresh();
        $enabled = $user->hasEnabledTwoFactorAuthentication();
        $pending = ! $enabled && ! is_null($user->two_factor_secret);

        return view('livewire.settings.two-factor', [
            'user' => $user,
            'enabled' => $enabled,
            'pending' => $pending,
            'qrCodeSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
        ]);
    }
}
