<div class="mx-auto w-full max-w-[1000px]">
    @section('title', 'Security · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('settings.index') }}" class="hover:text-text">Settings</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Security</span>
    @endsection

    <div class="min-w-0">
        <h1 class="text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Security</h1>
        <p class="mt-1.5 max-w-[78ch] text-[11.5px] text-text-sub">
            Two-factor authentication is optional for every role. It adds a 6-digit authenticator code at sign-in; password-only sign-in remains available while it is off.
        </p>
    </div>

    @include('livewire.settings.nav')

    @if ($statusMessage)
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-text-sub" role="status">{{ $statusMessage }}</p>
    @endif

    @if ($enabled)
        <section class="mt-8 max-w-[820px] border-t border-border pt-5" aria-labelledby="two-factor-enabled-heading">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 id="two-factor-enabled-heading" class="text-[13px] font-semibold text-text">Two-factor authentication</h2>
                    <p class="mt-1 max-w-[68ch] text-[10px] leading-5 text-text-sub">
                        This account requires a 6-digit authenticator code after its password at sign-in. Recovery codes are the only fallback if the authenticator device is lost.
                    </p>
                </div>

                <span class="inline-flex shrink-0 items-center gap-1.5 text-[10px] text-text-sub">
                    <span class="h-[6px] w-[6px] shrink-0 rounded-full bg-ok" aria-hidden="true"></span>
                    Enabled
                </span>
            </div>

            <dl class="mt-5 grid gap-x-8 gap-y-3 border-t border-divider pt-4 text-[10.5px] sm:grid-cols-2">
                <div>
                    <dt class="text-[8.5px] uppercase tracking-[0.08em] text-text-muted">Enabled on</dt>
                    <dd class="mt-1 font-mono text-text">
                        {{ $user->two_factor_confirmed_at?->timezone('Asia/Jakarta')->format('d M Y · H:i').' WIB' ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[8.5px] uppercase tracking-[0.08em] text-text-muted">Recovery codes remaining</dt>
                    <dd class="mt-1 font-mono text-text">{{ $recoveryCodesRemaining }}</dd>
                </div>
            </dl>

            @if ($recoveryCodes !== [])
                <div class="mt-6 border-y border-divider py-4" role="status" aria-labelledby="recovery-codes-heading">
                    <h3 id="recovery-codes-heading" class="text-[11.5px] font-medium text-text">Save these recovery codes now</h3>
                    <p class="mt-1 text-[10px] text-text-sub">
                        Each code works once and they will not be shown again. Store them somewhere safe and separate from the authenticator device.
                    </p>

                    <ul class="mt-3 grid gap-1 font-mono text-[11px] text-text sm:grid-cols-2" aria-label="Recovery codes">
                        @foreach ($recoveryCodes as $recoveryCode)
                            <li class="select-all">{{ $recoveryCode }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form wire:submit="regenerate" class="mt-8 max-w-[460px] border-t border-divider pt-5">
                <h3 class="text-[12px] font-medium text-text">Regenerate recovery codes</h3>
                <p class="mt-1 text-[10px] leading-5 text-text-sub">
                    Creates a new set of eight single-use codes. Every previous recovery code stops working immediately.
                </p>

                <div class="mt-4">
                    <label for="two-factor-regenerate-password" class="field-label">
                        Current password
                        <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                    </label>
                    <input
                        id="two-factor-regenerate-password"
                        type="password"
                        wire:model="current_password"
                        class="field"
                        autocomplete="current-password"
                        required
                        aria-required="true"
                        @if ($passwordAction === 'regenerate' && $errors->has('current_password')) aria-invalid="true" @endif
                    >
                    @if ($passwordAction === 'regenerate')
                        @error('current_password')
                            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    @endif
                </div>

                <button type="submit" class="secondary-button mt-5">Generate new recovery codes</button>
            </form>

            <form wire:submit="disable" class="mt-8 max-w-[460px] border-t border-divider pt-5">
                <h3 class="text-[12px] font-medium text-text">Disable two-factor authentication</h3>
                <p class="mt-1 text-[10px] leading-5 text-text-sub">
                    Removes the authenticator requirement and deletes the current recovery codes. This account will sign in with its password only.
                </p>

                <div class="mt-4">
                    <label for="two-factor-disable-password" class="field-label">
                        Current password
                        <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                    </label>
                    <input
                        id="two-factor-disable-password"
                        type="password"
                        wire:model="current_password"
                        class="field"
                        autocomplete="current-password"
                        required
                        aria-required="true"
                        @if ($passwordAction === 'disable' && $errors->has('current_password')) aria-invalid="true" @endif
                    >
                    @if ($passwordAction === 'disable')
                        @error('current_password')
                            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    @endif
                </div>

                <button type="submit" class="secondary-button mt-5 text-critical">Disable two-factor authentication</button>
            </form>
        </section>
    @elseif ($pending)
        <section class="mt-8 max-w-[820px] border-t border-border pt-5" aria-labelledby="two-factor-pending-heading">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 id="two-factor-pending-heading" class="text-[13px] font-semibold text-text">Finish setting up two-factor authentication</h2>
                    <p class="mt-1 max-w-[68ch] text-[10px] leading-5 text-text-sub">
                        Scan the QR code with an authenticator app, then enter its 6-digit code. Two-factor authentication is not active until you confirm a code.
                    </p>
                </div>

                <span class="inline-flex shrink-0 items-center gap-1.5 text-[10px] text-text-sub">
                    <span class="h-[6px] w-[6px] shrink-0 rounded-full bg-warning" aria-hidden="true"></span>
                    Setup incomplete
                </span>
            </div>

            <div class="mt-5 grid gap-6 border-t border-divider pt-5 sm:grid-cols-[192px_minmax(0,1fr)]">
                <div class="w-fit rounded-control border border-border bg-white p-2" role="img" aria-label="QR code for the NOC Center authenticator entry">
                    {!! $qrCodeSvg !!}
                </div>

                <form wire:submit="confirm" class="min-w-0">
                    <div class="max-w-[220px]">
                        <label for="two-factor-code" class="field-label">
                            Authentication code
                            <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
                        </label>
                        <input
                            id="two-factor-code"
                            type="text"
                            wire:model="code"
                            class="field font-mono tracking-[0.18em]"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            autocomplete="one-time-code"
                            required
                            aria-required="true"
                            @error('code') aria-invalid="true" @enderror
                        >
                        <p class="mt-1.5 text-[9px] text-text-muted">6 digits from the authenticator app.</p>
                        @error('code')
                            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="primary-button mt-5">Confirm and enable</button>
                </form>
            </div>

            <p class="mt-6 max-w-[68ch] border-t border-divider pt-4 text-[10px] leading-5 text-text-muted">
                Until setup is confirmed, this account keeps signing in with its password only. Reload this page if you need to see the QR code again.
            </p>
        </section>
    @else
        <section class="mt-8 max-w-[820px] border-t border-border pt-5" aria-labelledby="two-factor-disabled-heading">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 id="two-factor-disabled-heading" class="text-[13px] font-semibold text-text">Two-factor authentication</h2>
                    <p class="mt-1 max-w-[68ch] text-[10px] leading-5 text-text-sub">
                        Not enabled. This account signs in with its password only. Choose to add an authenticator code; no administrator approval or follow-up prompt is involved.
                    </p>
                </div>

                <span class="inline-flex shrink-0 items-center gap-1.5 text-[10px] text-text-muted">
                    <span class="h-[6px] w-[6px] shrink-0 rounded-full bg-border-strong" aria-hidden="true"></span>
                    Not enabled
                </span>
            </div>

            <div class="mt-5 border-t border-divider pt-5">
                <p class="max-w-[68ch] text-[10.5px] leading-5 text-text-sub">
                    Enabling two-factor authentication adds a 6-digit code from an authenticator app after your password. You will receive eight single-use recovery codes in case the device is lost.
                </p>

                <button type="button" wire:click="enable" class="primary-button mt-5">Set up two-factor authentication</button>
            </div>
        </section>
    @endif
</div>
