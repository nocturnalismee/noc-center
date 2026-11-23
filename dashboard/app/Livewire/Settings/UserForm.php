<?php

namespace App\Livewire\Settings;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * Create/edit form used by the users page.
 *
 * Kept as its own component so the list page stays an actions-only surface
 * (spec §13). The form never sends or receives a stored password: only a new
 * administrator-set password on create, which marks the account
 * `must_change_password`.
 */
class UserForm extends Component
{
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'viewer';

    public bool $isActive = true;

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(?int $userId = null): void
    {
        $this->userId = $userId;

        if ($userId !== null) {
            $target = User::query()->findOrFail($userId);

            Gate::authorize('update', $target);

            $this->name = $target->name;
            $this->email = $target->email;
            $this->role = $target->role()->value;
            $this->isActive = (bool) $target->is_active;

            return;
        }

        Gate::authorize('create', User::class);
    }

    public function save(): void
    {
        $creating = $this->userId === null;
        $target = $creating ? null : User::query()->findOrFail((int) $this->userId);

        if ($creating) {
            Gate::authorize('create', User::class);
        } else {
            Gate::authorize('update', $target);
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->userId),
            ],
            'role' => ['required', Rule::in(array_column(UserRole::cases(), 'value'))],
            'isActive' => ['boolean'],
            'password' => $creating
                ? ['required', 'string', Password::min(8), 'same:passwordConfirmation']
                : ['nullable'],
        ]);

        if ($target !== null && $this->wouldRemoveTheLastAdmin($target)) {
            if ($this->role !== UserRole::Admin->value) {
                $this->addError('role', 'The last active administrator cannot be demoted.');
            }

            if (! $this->isActive) {
                $this->addError('isActive', 'The last active administrator cannot be deactivated.');
            }

            return;
        }

        $role = UserRole::from($this->role);

        if ($creating) {
            $user = new User;
            $user->forceFill([
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
                'role' => $role,
                'is_active' => $this->isActive,
                'must_change_password' => true,
                'email_verified_at' => null,
            ])->save();

            app(AuditLogger::class)->log('user.created', $user, [
                'user_id' => $user->getKey(),
                'role' => $role->value,
                'is_active' => (bool) $user->is_active,
            ]);

            $this->dispatch('user-saved');

            return;
        }

        $removedLastAdmin = DB::transaction(function () use ($target, $role): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($this->wouldRemoveTheLastAdmin($locked)) {
                return true;
            }

            $oldRole = $locked->role();
            $oldActive = (bool) $locked->is_active;
            $changed = [];

            foreach (['name' => $this->name, 'email' => $this->email] as $field => $value) {
                if ($locked->{$field} !== $value) {
                    $changed[] = $field;
                }
            }

            if ($oldRole->value !== $role->value) {
                $changed[] = 'role';
            }

            if ($oldActive !== $this->isActive) {
                $changed[] = 'is_active';
            }

            $locked->forceFill([
                'name' => $this->name,
                'email' => $this->email,
                'role' => $role,
                'is_active' => $this->isActive,
            ])->save();

            if ($changed !== []) {
                app(AuditLogger::class)->log('user.updated', $locked, [
                    'user_id' => $locked->getKey(),
                    'changed' => $changed,
                ]);
            }

            if ($oldRole->value !== $role->value) {
                app(AuditLogger::class)->log('user.role_changed', $locked, [
                    'user_id' => $locked->getKey(),
                    'old_role' => $oldRole->value,
                    'new_role' => $role->value,
                ]);
            }

            if ($oldActive !== $this->isActive) {
                app(AuditLogger::class)->log(
                    $this->isActive ? 'user.activated' : 'user.deactivated',
                    $locked,
                    ['user_id' => $locked->getKey()],
                );
            }

            return false;
        });

        if ($removedLastAdmin) {
            if ($this->role !== UserRole::Admin->value) {
                $this->addError('role', 'The last active administrator cannot be demoted.');
            }

            if (! $this->isActive) {
                $this->addError('isActive', 'The last active administrator cannot be deactivated.');
            }

            return;
        }

        $this->dispatch('user-saved');
    }

    public function cancel(): void
    {
        $this->dispatch('user-cancelled');
    }

    public function render(): View
    {
        return view('livewire.settings.user-form', [
            'roles' => UserRole::cases(),
            'creating' => $this->userId === null,
        ]);
    }

    private function wouldRemoveTheLastAdmin(User $target): bool
    {
        if ($target->role() !== UserRole::Admin || ! $target->is_active) {
            return false;
        }

        if ($this->role === UserRole::Admin->value && $this->isActive) {
            return false;
        }

        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->where('id', '!=', $target->getKey())
            ->doesntExist();
    }
}
