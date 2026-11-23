<div class="mx-auto w-full max-w-[1200px]">
    @section('title', 'Users · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('settings.index') }}" class="hover:text-text">Settings</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Users</span>
    @endsection

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Users</h1>
            <p class="mt-1.5 max-w-[76ch] text-[11.5px] text-text-sub">
                Accounts that can sign in to this dashboard. Administrator-set passwords must be changed at next sign-in.
            </p>
        </div>

        <button type="button" wire:click="openCreate" class="primary-button shrink-0">Add user</button>
    </div>

    @include('livewire.settings.nav')

    @if ($statusMessage)
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-text-sub" role="status">{{ $statusMessage }}</p>
    @endif

    @error('delete')
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-critical" role="alert">{{ $message }}</p>
    @enderror

    @if ($showForm)
        <section class="mt-6 border-t border-border pt-5" aria-labelledby="user-form-heading">
            <div class="flex items-baseline justify-between gap-4">
                <h2 id="user-form-heading" class="text-[13px] font-semibold text-text">
                    {{ $editingId ? 'Edit user' : 'New user' }}
                </h2>
                <button type="button" wire:click="closeForm" class="text-[10.5px] text-text-sub hover:text-text">Cancel</button>
            </div>

            <livewire:settings.user-form
                :key="'user-form-'.$formKey"
                :user-id="$editingId"
            />
        </section>
    @endif

    <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <label for="user-search" class="sr-only">Search users</label>
        <input
            id="user-search"
            type="search"
            wire:model.live.debounce.300ms="q"
            class="field h-9 max-w-xs"
            placeholder="Search name or email"
            autocomplete="off"
        >

        <p class="font-mono text-[9.5px] text-text-muted">{{ $users->total() }} {{ Str::plural('account', $users->total()) }}</p>
    </div>

    @if ($users->isEmpty())
        <x-empty-state class="mt-4">
            @if ($q !== '')
                No user matches “{{ $q }}”.
            @else
                No users yet.
            @endif
        </x-empty-state>
    @else
        <div class="mt-2 overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-border text-[8.5px] uppercase tracking-[0.08em] text-text-muted">
                        <th scope="col" class="py-2 pr-4 font-medium">User</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Role</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Status</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Last sign-in</th>
                        <th scope="col" class="py-2 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr class="border-b border-divider align-top text-[10.5px]" wire:key="user-{{ $user->id }}">
                            <td class="py-3 pr-4">
                                <p class="text-[12px] font-medium text-text">{{ $user->name }}</p>
                                <p class="mt-0.5 font-mono text-[9.5px] text-text-muted">{{ $user->email }}</p>
                            </td>
                            <td class="py-3 pr-4 text-text-sub">{{ ucfirst($user->role()->value) }}</td>
                            <td class="py-3 pr-4">
                                <span @class([
                                    'inline-flex items-center gap-1.5',
                                    'text-text-sub' => $user->is_active,
                                    'text-text-muted' => ! $user->is_active,
                                ])>
                                    <span aria-hidden="true" @class([
                                        'h-[6px] w-[6px] shrink-0 rounded-full',
                                        'bg-ok' => $user->is_active,
                                        'bg-border-strong' => ! $user->is_active,
                                    ])></span>
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                                @if ($user->must_change_password)
                                    <p class="mt-1 text-[9px] text-text-muted">Password change required</p>
                                @endif
                            </td>
                            <td class="py-3 pr-4 font-mono text-[9.5px] text-text-muted">
                                {{ $user->last_login_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i') ?? '—' }}
                            </td>
                            <td class="py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-3 text-[10px]">
                                    <button type="button" wire:click="openEdit({{ $user->id }})" class="text-text-sub hover:text-text">Edit</button>
                                    <button type="button" wire:click="startReset({{ $user->id }})" class="text-text-sub hover:text-text">Reset password</button>
                                    <button
                                        type="button"
                                        wire:click="delete({{ $user->id }})"
                                        wire:confirm="Delete {{ $user->name }}? This cannot be undone."
                                        class="text-text-sub hover:text-critical"
                                    >Delete</button>
                                </div>

                                @if ($resetFor === $user->id)
                                    <form wire:submit="resetPassword" class="mt-3 border-t border-divider pt-3 text-left">
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <label for="new-password-{{ $user->id }}" class="field-label">New password</label>
                                                <input
                                                    id="new-password-{{ $user->id }}"
                                                    type="password"
                                                    wire:model="newPassword"
                                                    class="field"
                                                    autocomplete="new-password"
                                                    aria-describedby="new-password-help-{{ $user->id }}"
                                                    @error('newPassword') aria-invalid="true" @enderror
                                                >
                                                <p id="new-password-help-{{ $user->id }}" class="mt-1.5 text-[9px] text-text-muted">
                                                    The user must change it at next sign-in.
                                                </p>
                                                @error('newPassword')
                                                    <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label for="new-password-confirmation-{{ $user->id }}" class="field-label">Confirm password</label>
                                                <input
                                                    id="new-password-confirmation-{{ $user->id }}"
                                                    type="password"
                                                    wire:model="newPasswordConfirmation"
                                                    class="field"
                                                    autocomplete="new-password"
                                                >
                                            </div>
                                        </div>

                                        <div class="mt-3 flex gap-2">
                                            <button type="submit" class="secondary-button h-8">Reset password</button>
                                            <button type="button" wire:click="cancelReset" class="secondary-button h-8">Cancel</button>
                                        </div>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $users->links() }}
        </div>
    @endif
</div>
