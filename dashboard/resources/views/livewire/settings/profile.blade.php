<div class="mx-auto w-full max-w-[1000px]">
    @section('title', 'Profile · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('settings.index') }}" class="hover:text-text">Settings</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Profile</span>
    @endsection

    <div class="min-w-0">
        <h1 class="text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Profile</h1>
        <p class="mt-1.5 max-w-[72ch] text-[11.5px] text-text-sub">
            Your account details, alert email preference, and password. Changing your email requires your current password.
        </p>
    </div>

    @include('livewire.settings.nav')

    @if ($user->must_change_password)
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-warning" role="status">
            An administrator set a temporary password. Choose a new password below to continue.
        </p>
    @endif

    @if ($statusMessage)
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-text-sub" role="status">{{ $statusMessage }}</p>
    @endif

    <form wire:submit="saveProfile" class="mt-8 grid gap-10 xl:grid-cols-[minmax(0,760px)_minmax(0,1fr)]">
        <section class="border-t border-border pt-5" aria-labelledby="profile-details-heading">
            <h2 id="profile-details-heading" class="text-[13px] font-semibold text-text">Account details</h2>
            <p class="mt-1 text-[10px] text-text-sub">Used for sign-in and alert email delivery.</p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="profile-name" class="field-label">
                        Name
                        <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                    </label>
                    <input
                        id="profile-name"
                        type="text"
                        wire:model="name"
                        class="field"
                        autocomplete="name"
                        required
                        aria-required="true"
                        @error('name') aria-invalid="true" @enderror
                    >
                    @error('name')
                        <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="profile-email" class="field-label">
                        Email
                        <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                    </label>
                    <input
                        id="profile-email"
                        type="email"
                        wire:model="email"
                        class="field"
                        autocomplete="email"
                        spellcheck="false"
                        required
                        aria-required="true"
                        aria-describedby="profile-email-help"
                        @error('email') aria-invalid="true" @enderror
                    >
                    <p id="profile-email-help" class="mt-1.5 text-[9px] text-text-muted">
                        {{ $user->email_verified_at ? 'Verified' : 'Not verified' }}. A change clears verification.
                    </p>
                    @error('email')
                        <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="profile-current-password" class="field-label">Current password (email change only)</label>
                    <input
                        id="profile-current-password"
                        type="password"
                        wire:model="emailPassword"
                        class="field"
                        autocomplete="current-password"
                        aria-describedby="profile-current-password-help"
                        @error('emailPassword') aria-invalid="true" @enderror
                    >
                    <p id="profile-current-password-help" class="mt-1.5 text-[9px] text-text-muted">Leave blank unless you are changing your email.</p>
                    @error('emailPassword')
                        <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <span class="field-label">Alert email</span>
                    <label for="profile-notify" class="mt-2 inline-flex min-h-11 cursor-pointer items-center gap-2.5 text-[11.5px] text-text">
                        <input id="profile-notify" type="checkbox" wire:model="notifyEmail" class="h-4 w-4 rounded border-border bg-surface-2">
                        Receive alert email
                    </label>
                    <p class="text-[9px] text-text-muted">Applies to this account only. In-app alerts are always available.</p>
                    @error('notifyEmail')
                        <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-6">
                <button type="submit" class="primary-button">Save profile</button>
            </div>
        </section>

        <p class="max-w-[34ch] border-t border-border pt-5 text-[10px] leading-5 text-text-muted xl:mt-0">
            Timezone in the interface follows Asia/Jakarta. Stored timestamps remain UTC.
        </p>
    </form>

    <form wire:submit="savePassword" class="mt-10 max-w-[760px]">
        <section class="border-t border-border pt-5" aria-labelledby="profile-password-heading">
            <h2 id="profile-password-heading" class="text-[13px] font-semibold text-text">Password</h2>
            <p class="mt-1 text-[10px] text-text-sub">Use at least 8 characters. Changing it clears any temporary-password requirement.</p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="profile-password-current" class="field-label">
                        Current password
                        <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                    </label>
                    <input
                        id="profile-password-current"
                        type="password"
                        wire:model="currentPassword"
                        class="field"
                        autocomplete="current-password"
                        required
                        aria-required="true"
                        @error('currentPassword') aria-invalid="true" @enderror
                    >
                    @error('currentPassword')
                        <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="profile-password-new" class="field-label">
                        New password
                        <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                    </label>
                    <input
                        id="profile-password-new"
                        type="password"
                        wire:model="newPassword"
                        class="field"
                        autocomplete="new-password"
                        required
                        aria-required="true"
                        @error('newPassword') aria-invalid="true" @enderror
                    >
                    @error('newPassword')
                        <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="profile-password-confirmation" class="field-label">
                        Confirm new password
                        <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                    </label>
                    <input
                        id="profile-password-confirmation"
                        type="password"
                        wire:model="newPasswordConfirmation"
                        class="field"
                        autocomplete="new-password"
                        required
                        aria-required="true"
                    >
                </div>
            </div>

            <div class="mt-6">
                <button type="submit" class="primary-button">Change password</button>
            </div>
        </section>
    </form>
</div>
