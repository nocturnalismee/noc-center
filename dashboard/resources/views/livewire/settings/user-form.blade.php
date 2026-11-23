<form wire:submit="save" class="mt-4 grid gap-5 sm:grid-cols-2">
    <div>
        <label for="user-name" class="field-label">
            Name
            <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
        </label>
        <input
            id="user-name"
            type="text"
            wire:model="name"
            class="field"
            autocomplete="off"
            required
            aria-required="true"
            @error('name') aria-invalid="true" @enderror
        >
        @error('name')
            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="user-email" class="field-label">
            Email
            <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
        </label>
        <input
            id="user-email"
            type="email"
            wire:model="email"
            class="field"
            autocomplete="off"
            spellcheck="false"
            required
            aria-required="true"
            @error('email') aria-invalid="true" @enderror
        >
        @error('email')
            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="user-role" class="field-label">Role</label>
        <select id="user-role" wire:model="role" class="field" @error('role') aria-invalid="true" @enderror>
            @foreach ($roles as $role)
                <option value="{{ $role->value }}">{{ ucfirst($role->value) }}</option>
            @endforeach
        </select>
        <p class="mt-1.5 text-[9px] text-text-muted">Viewers read the dashboard, operators also act on servers and alerts.</p>
        @error('role')
            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <span class="field-label">Account state</span>
        <label for="user-active" class="mt-2 inline-flex min-h-11 cursor-pointer items-center gap-2.5 text-[11.5px] text-text">
            <input id="user-active" type="checkbox" wire:model="isActive" class="h-4 w-4 rounded border-border bg-surface-2">
            Active
        </label>
        <p class="text-[9px] text-text-muted">Inactive accounts cannot sign in. Alerts skip them.</p>
        @error('isActive')
            <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
        @enderror
    </div>

    @if ($creating)
        <div>
            <label for="user-password" class="field-label">
                Temporary password
                <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
            </label>
            <input
                id="user-password"
                type="password"
                wire:model="password"
                class="field"
                autocomplete="new-password"
                required
                aria-required="true"
                @error('password') aria-invalid="true" @enderror
            >
            <p class="mt-1.5 text-[9px] text-text-muted">At least 8 characters. The user must change it at next sign-in.</p>
            @error('password')
                <p class="mt-1.5 text-[9.5px] text-critical">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="user-password-confirmation" class="field-label">
                Confirm password
                <span class="ml-1 inline-block h-[3px] w-[3px] rounded-full bg-critical align-middle" aria-hidden="true"></span>
            </label>
            <input
                id="user-password-confirmation"
                type="password"
                wire:model="passwordConfirmation"
                class="field"
                autocomplete="new-password"
                required
                aria-required="true"
            >
        </div>
    @endif

    <div class="flex gap-2 sm:col-span-2">
        <button type="submit" class="primary-button">{{ $creating ? 'Create user' : 'Save changes' }}</button>
        <button type="button" wire:click="cancel" class="secondary-button">Cancel</button>
    </div>
</form>
