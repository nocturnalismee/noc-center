<?php

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Services\Tokens\TokenService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Tokens extends Component
{
    public Server $server;

    public ?string $revokingTokenId = null;

    public string $revokeName = '';

    public ?string $statusMessage = null;

    public function mount(Server $server): void
    {
        Gate::authorize('view', $server);

        $this->server = $server;
    }

    /**
     * Rotate the current token and hand the new plaintext to the one-time
     * reveal controller exactly like the create wizard (Task 18).
     *
     * The plaintext never becomes a public property, a URL segment, or a
     * Livewire payload: only `Crypt::encryptString($plain)` is flashed.
     */
    public function rotate(): void
    {
        Gate::authorize('manageTokens', Server::class);

        $issued = app(TokenService::class)->rotate($this->server, 1440, auth()->user());

        session()->flash('issued_token', [
            'server_id' => $this->server->getKey(),
            'token_cipher' => Crypt::encryptString($issued->plain),
            'prefix' => $issued->token->token_prefix,
        ]);

        $this->redirectRoute('servers.token-reveal', ['server' => $this->server]);
    }

    public function startRevoke(string $tokenId): void
    {
        Gate::authorize('manageTokens', Server::class);

        $this->revokingTokenId = $tokenId;
        $this->revokeName = '';
        $this->resetErrorBag();
    }

    public function cancelRevoke(): void
    {
        $this->revokingTokenId = null;
        $this->revokeName = '';
        $this->resetErrorBag();
    }

    /**
     * Revoke a token only after the operator types the exact server name.
     */
    public function revoke(): void
    {
        Gate::authorize('manageTokens', Server::class);

        $this->resetErrorBag();

        $token = $this->revokingTokenId === null
            ? null
            : $this->server->tokens()->whereKey($this->revokingTokenId)->first();

        if ($token === null) {
            $this->addError('revokeName', 'Select a token to revoke.');

            return;
        }

        if (trim($this->revokeName) !== $this->server->name) {
            $this->addError('revokeName', 'Type the server name exactly to revoke this token.');

            return;
        }

        app(TokenService::class)->revoke($token, auth()->user());

        $this->revokingTokenId = null;
        $this->revokeName = '';
        $this->statusMessage = 'Token revoked.';
    }

    public function tokenStatus(ServerToken $token): string
    {
        if ($token->revoked_at !== null) {
            return 'Revoked';
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return 'Expired';
        }

        return 'Active';
    }

    public function tokenExpiry(ServerToken $token, bool $grace = false): string
    {
        if ($token->revoked_at !== null) {
            return 'Revoked '.$this->formatDate($token->revoked_at);
        }

        if ($token->expires_at === null) {
            return 'Never';
        }

        if ($token->expires_at->isPast()) {
            return 'Expired '.$this->formatDate($token->expires_at);
        }

        return ($grace ? 'Grace ends ' : 'Expires ')
            .$this->formatDate($token->expires_at)
            .' · '.$this->countdown($token->expires_at);
    }

    public function render(): View
    {
        $tokens = $this->server->tokens()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $creators = User::query()
            ->whereIn('id', $tokens->pluck('created_by')->filter()->unique()->all())
            ->pluck('name', 'id');

        $graceIds = array_flip(
            ServerToken::query()
                ->whereIn('rotated_from_id', $tokens->pluck('id')->all())
                ->pluck('rotated_from_id')
                ->all(),
        );

        return view('livewire.servers.tokens', [
            'tokens' => $tokens,
            'creators' => $creators,
            'graceIds' => $graceIds,
            'canManage' => auth()->user()?->can('manageTokens', Server::class) ?? false,
        ]);
    }

    private function formatDate(CarbonInterface $at): string
    {
        return $at->timezone('Asia/Jakarta')->format('d M Y · H:i').' WIB';
    }

    private function countdown(CarbonInterface $at): string
    {
        $seconds = max(0, (int) now()->diffInSeconds($at, false));
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('in %dd %dh', $days, $hours);
        }

        if ($hours > 0) {
            return sprintf('in %dh %dm', $hours, $minutes);
        }

        return sprintf('in %dm', $minutes);
    }
}
