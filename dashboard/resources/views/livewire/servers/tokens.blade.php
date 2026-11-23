<div class="mx-auto w-full max-w-[1440px]">
    @section('title', 'Tokens · '.$server->name.' · NOC Center')
    @section('breadcrumb')
        <a href="{{ route('dashboard') }}" class="hover:text-text">Fleet</a>
        <span class="px-2 text-border-strong">/</span>
        <a href="{{ route('servers.show', $server) }}" class="hover:text-text">{{ $server->name }}</a>
        <span class="px-2 text-border-strong">/</span>
        <span class="text-text">Tokens</span>
    @endsection

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-[22px] font-semibold leading-7 tracking-[-0.01em] text-text">Agent tokens</h1>
            <p class="mt-1.5 max-w-[70ch] text-[11.5px] text-text-sub">
                Active, grace, and revoked tokens for {{ $server->name }}. Only an HMAC hash is stored;
                a token is shown once at issue or rotation.
            </p>
            <p class="mt-1 text-[10px] text-text-muted">
                Grace window: 24 hours for the previous token after a rotation.
            </p>
        </div>

        @if ($canManage)
            <button type="button" wire:click="rotate" class="primary-button">Rotate token</button>
        @else
            <p class="text-[10px] text-text-muted">Read-only view. Operators and administrators can rotate or revoke.</p>
        @endif
    </div>

    @if ($statusMessage)
        <p class="mt-4 border-y border-divider py-2.5 text-[10.5px] text-text-sub" role="status">{{ $statusMessage }}</p>
    @endif

    <div class="mt-6 overflow-x-auto">
        <table class="w-full border-collapse text-left">
            <thead>
                <tr class="border-y border-border text-[8.5px] uppercase tracking-[0.08em] text-text-muted">
                    <th scope="col" class="py-2 pr-4 font-medium">Prefix</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Status</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Label</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Created</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Last used</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Expires</th>
                    <th scope="col" class="py-2 pr-4 font-medium">Created by</th>
                    @if ($canManage)
                        <th scope="col" class="py-2 text-right font-medium">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($tokens as $token)
                    @php($status = $this->tokenStatus($token))
                    <tr class="border-b border-divider align-top text-[10.5px]" wire:key="token-{{ $token->id }}">
                        <td class="py-3 pr-4 font-mono text-[10.5px] text-text">{{ $token->token_prefix }}…</td>
                        <td class="py-3 pr-4">
                            <span @class([
                                'font-medium',
                                'text-ok' => $status === 'Active',
                                'text-warning' => $status === 'Expired',
                                'text-text-muted' => $status === 'Revoked',
                            ])>{{ $status }}</span>
                        </td>
                        <td class="py-3 pr-4 text-text-sub">{{ $token->label ?: '—' }}</td>
                        <td class="py-3 pr-4 text-[9.5px] text-text-muted">{{ $token->created_at?->timezone('Asia/Jakarta')->format('d M Y · H:i').' WIB' }}</td>
                        <td class="py-3 pr-4 text-[9.5px] text-text-muted">
                            {{ $token->last_used_at ? $token->last_used_at->timezone('Asia/Jakarta')->format('d M Y · H:i').' WIB' : 'Never' }}
                        </td>
                        <td class="py-3 pr-4 text-[9.5px] text-text-muted">
                            {{ $this->tokenExpiry($token, isset($graceIds[$token->id])) }}
                        </td>
                        <td class="py-3 pr-4 text-[9.5px] text-text-sub">
                            {{ $token->created_by !== null ? ($creators[$token->created_by] ?? 'User #'.$token->created_by) : 'System' }}
                        </td>
                        @if ($canManage)
                            <td class="py-3 text-right">
                                @if ($status === 'Active' || $status === 'Expired')
                                    @if ($revokingTokenId === $token->id)
                                        <div class="flex flex-col items-end gap-2">
                                            <label for="revoke-name-{{ $token->id }}" class="text-[9px] text-text-muted">
                                                Type <span class="font-mono text-text">{{ $server->name }}</span> to revoke
                                            </label>
                                            <input
                                                id="revoke-name-{{ $token->id }}"
                                                type="text"
                                                wire:model="revokeName"
                                                class="field h-9 w-56"
                                                autocomplete="off"
                                            >
                                            @error('revokeName')
                                                <p class="text-[9.5px] text-critical">{{ $message }}</p>
                                            @enderror
                                            <div class="flex items-center gap-2">
                                                <button type="button" wire:click="cancelRevoke" class="secondary-button h-8 px-3 text-[10px]">Cancel</button>
                                                <button type="button" wire:click="revoke" class="secondary-button h-8 px-3 text-[10px]">Revoke token</button>
                                            </div>
                                        </div>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="startRevoke('{{ $token->id }}')"
                                            class="secondary-button h-8 px-3 text-[10px]"
                                            aria-label="Revoke token {{ $token->token_prefix }}"
                                        >Revoke</button>
                                    @endif
                                @else
                                    <span class="text-[9px] text-text-muted">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canManage ? 8 : 7 }}" class="border-b border-divider py-8 text-center text-[10.5px] text-text-muted">
                            No tokens have been issued for this server.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-[9px] text-text-muted">
        Rotating keeps the current token valid for 24 hours. Revoking is immediate and cannot be undone.
    </p>
</div>
