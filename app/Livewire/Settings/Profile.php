<?php

namespace App\Livewire\Settings;

use App\Services\AuditLogger;
use App\Services\Users\UserSessions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The signed-in user's own profile (spec §12, §14).
 *
 * Changing the email address requires the current password and resets
 * `email_verified_at`. Changing the password clears `must_change_password`
 * so an administrator-set temporary password stops forcing the redirect.
 * Audit metadata never contains a password.
 */
#[Layout('layouts.app')]
class Profile extends Component
{
    public string $name = '';

    public string $email = '';

    public bool $notifyEmail = true;

    public string $emailPassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->notifyEmail = (bool) $user->notify_email;
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $this->email = Str::lower(trim($this->email));
        $emailChanged = $this->email !== $user->email;

        $this->statusMessage = null;

        try {
            $this->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($user->getKey()),
                ],
                'notifyEmail' => ['boolean'],
                'emailPassword' => $emailChanged
                    ? ['required', 'string', 'current_password:web']
                    : ['nullable'],
            ]);
        } catch (ValidationException $exception) {
            $this->emailPassword = '';

            throw $exception;
        }

        $changed = [];

        if ($this->name !== $user->name) {
            $changed[] = 'name';
        }

        if ($emailChanged) {
            $changed[] = 'email';
        }

        if ((bool) $user->notify_email !== $this->notifyEmail) {
            $changed[] = 'notify_email';
        }

        $user->forceFill([
            'name' => $this->name,
            'email' => $this->email,
            'notify_email' => $this->notifyEmail,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        if ($changed !== []) {
            app(AuditLogger::class)->log('user.profile_updated', $user, [
                'user_id' => $user->getKey(),
                'changed' => $changed,
            ]);
        }

        if ($emailChanged) {
            app(AuditLogger::class)->log('user.email_changed', $user, [
                'user_id' => $user->getKey(),
                'email_verified' => false,
            ]);
        }

        $this->emailPassword = '';
        $this->statusMessage = 'Profile updated.';
    }

    public function savePassword(): void
    {
        $user = auth()->user();

        $this->statusMessage = null;

        try {
            $this->validate([
                'currentPassword' => ['required', 'string', 'current_password:web'],
                'newPassword' => ['required', 'string', Password::min(12), 'same:newPasswordConfirmation'],
            ]);
        } catch (ValidationException $exception) {
            $this->currentPassword = '';

            throw $exception;
        }

        $user->forceFill([
            'password' => $this->newPassword,
            'must_change_password' => false,
        ])->save();

        $sessions = app(UserSessions::class);
        $sessions->rotateRememberToken($user);
        $sessions->purge($user, session()->getId());

        app(AuditLogger::class)->log('user.password_changed', $user, [
            'user_id' => $user->getKey(),
            'must_change_password' => false,
        ]);

        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
        $this->statusMessage = 'Password changed.';
    }

    public function render(): View
    {
        return view('livewire.settings.profile', [
            'user' => auth()->user(),
        ]);
    }
}
