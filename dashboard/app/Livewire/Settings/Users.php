<?php

namespace App\Livewire\Settings;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Users\UserSessions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin-only user administration (spec §12, §17.1).
 *
 * Role, activation state and administrator-set passwords are never mass
 * assignable: `User::$fillable` stays name/email/password/notify_email (R25),
 * so every account-state write goes through `forceFill()` here.
 *
 * The last active administrator cannot be demoted, deactivated or deleted:
 * losing every administrator would lock the installation out of Settings.
 */
#[Layout('layouts.app')]
class Users extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $q = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public int $formKey = 0;

    public ?int $resetFor = null;

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function updated(string $property): void
    {
        if ($property === 'q') {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        Gate::authorize('create', User::class);

        $this->editingId = null;
        $this->formKey++;
        $this->showForm = true;
        $this->statusMessage = null;
    }

    public function openEdit(int $userId): void
    {
        $target = User::query()->findOrFail($userId);

        Gate::authorize('update', $target);

        $this->editingId = $userId;
        $this->formKey++;
        $this->showForm = true;
        $this->resetFor = null;
        $this->statusMessage = null;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    #[On('user-cancelled')]
    public function cancelForm(): void
    {
        $this->closeForm();
    }

    #[On('user-saved')]
    public function userSaved(): void
    {
        $this->closeForm();
        $this->statusMessage = 'User saved.';
    }

    public function startReset(int $userId): void
    {
        $target = User::query()->findOrFail($userId);

        Gate::authorize('update', $target);

        $this->resetFor = $userId;
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
        $this->statusMessage = null;
        $this->resetErrorBag();
    }

    public function cancelReset(): void
    {
        $this->resetFor = null;
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
        $this->resetErrorBag();
    }

    public function resetPassword(): void
    {
        $target = User::query()->findOrFail((int) $this->resetFor);

        Gate::authorize('update', $target);

        $this->validate([
            'newPassword' => ['required', 'string', Password::min(12), 'same:newPasswordConfirmation'],
        ]);

        $target->forceFill([
            'password' => $this->newPassword,
            'must_change_password' => true,
        ])->save();

        $sessions = app(UserSessions::class);
        $sessions->rotateRememberToken($target);
        $sessions->purge(
            $target,
            $target->getKey() === auth()->id() ? session()->getId() : null,
        );

        app(AuditLogger::class)->log('user.password_reset', $target, [
            'user_id' => $target->getKey(),
            'must_change_password' => true,
        ]);

        $this->cancelReset();
        $this->statusMessage = 'Password reset. The user must choose a new password at next sign-in.';
    }

    public function delete(int $userId): void
    {
        $target = User::query()->findOrFail($userId);

        Gate::authorize('delete', $target);

        $deleted = DB::transaction(function () use ($target, $userId): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($this->isLastActiveAdmin($locked)) {
                return false;
            }

            app(AuditLogger::class)->log('user.deleted', null, [
                'user_id' => $userId,
                'email' => $locked->email,
                'role' => $locked->role()->value,
            ]);

            $locked->delete();

            return true;
        });

        if (! $deleted) {
            $this->addError('delete', 'The last active administrator cannot be deleted.');

            return;
        }

        $this->closeForm();
        $this->statusMessage = 'User deleted.';
    }

    private function isLastActiveAdmin(User $target): bool
    {
        if ($target->role() !== UserRole::Admin || ! $target->is_active) {
            return false;
        }

        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->where('id', '!=', $target->getKey())
            ->doesntExist();
    }

    public function render(): View
    {
        $query = User::query()
            ->when($this->q !== '', function ($query): void {
                $term = '%'.$this->q.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('name');

        return view('livewire.settings.users', [
            'users' => $query->paginate(25),
        ]);
    }
}
