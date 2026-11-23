<?php

namespace App\Services\Tokens;

use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TokenService
{
    /**
     * `noc_` plus 43 base64url characters (32 random bytes, unpadded).
     *
     * Anchored with `\A`/`\z` so a trailing newline cannot slip past the
     * check and reach the database.
     */
    private const PATTERN = '/\Anoc_[A-Za-z0-9_-]{43}\z/';

    public function __construct(private readonly AuditLogger $audit)
    {
        //
    }

    /**
     * Mint a new agent token. The plaintext is returned once and never stored.
     */
    public function issue(
        Server $server,
        ?User $actor = null,
        ?string $label = null,
        ?CarbonInterface $expiresAt = null,
    ): IssuedToken {
        $issued = $this->createToken($server, $actor, $label, $expiresAt);

        $this->audit->log(
            'token.created',
            $issued->token,
            $this->auditMeta($issued->token),
            $actor?->id,
        );

        return $issued;
    }

    /**
     * Rotate the server's current token, keeping it valid for the grace window.
     *
     * Rotation never extends an existing earlier expiry: when the previous
     * token already expires before the grace window elapses, its own
     * `expires_at` is preserved. A `graceMinutes` of `0` expires the previous
     * token immediately; a negative window is rejected with
     * {@see InvalidArgumentException}.
     *
     * When the server has no usable current token (never issued, revoked or
     * expired), rotation still mints a new token with `rotated_from_id = null`
     * and audits `token.rotated`: an explicit, recorded lineage break.
     *
     * The grace window defaults to `config('tokens.grace_minutes')` (1440
     * minutes unless configured); pass an integer to override it per call.
     *
     * @throws InvalidArgumentException
     */
    public function rotate(Server $server, ?int $graceMinutes = null, ?User $actor = null): IssuedToken
    {
        $graceMinutes ??= (int) config('tokens.grace_minutes', 1440);
        if ($graceMinutes < 0) {
            throw new InvalidArgumentException('Rotation grace minutes must be zero or greater.');
        }

        return DB::transaction(function () use ($server, $graceMinutes, $actor): IssuedToken {
            $previous = $this->currentToken($server);

            $issued = $this->createToken(
                $server,
                $actor,
                $previous?->label,
                null,
                $previous?->getKey(),
            );

            if ($previous !== null) {
                $graceExpiresAt = now()->addMinutes($graceMinutes);
                $existingExpiry = $previous->expires_at;

                $previous->expires_at = $existingExpiry !== null && $existingExpiry->lessThan($graceExpiresAt)
                    ? $existingExpiry
                    : $graceExpiresAt;

                $previous->save();
            }

            $this->audit->log(
                'token.rotated',
                $issued->token,
                $this->auditMeta($issued->token),
                $actor?->id,
            );

            return $issued;
        });
    }

    /**
     * Revoke a token immediately.
     *
     * Revocation is idempotent: an already-revoked token keeps its original
     * `revoked_at` and appends no second `token.revoked` audit row.
     */
    public function revoke(ServerToken $token, ?User $actor = null): void
    {
        if ($token->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($token, $actor): void {
            $token->forceFill(['revoked_at' => now()])->save();

            $this->audit->log(
                'token.revoked',
                $token,
                $this->auditMeta($token),
                $actor?->id,
            );
        });
    }

    /**
     * Resolve a plaintext token without considering the server's state.
     *
     * A valid token may belong to an inactive or soft-deleted server so the
     * ingest middleware can answer 403 instead of 401 (R20). The `server`
     * relation is eager-loaded with the trashed scope so a soft-deleted
     * server stays inspectable instead of becoming `null`. Malformed input is
     * rejected before any database query.
     */
    public function resolve(string $plain): ?ServerToken
    {
        if (preg_match(self::PATTERN, $plain) !== 1) {
            return null;
        }

        return ServerToken::query()
            ->with(['server' => fn ($query) => $query->withTrashed()])
            ->where('token_hash', $this->hash($plain))
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->first();
    }

    /**
     * Authenticate an agent request: a valid token on an active, existing server.
     */
    public function authenticate(string $plain): ?ServerToken
    {
        $token = $this->resolve($plain);

        if ($token === null) {
            return null;
        }

        $server = $token->server;

        if ($server === null || $server->trashed() || $server->is_active !== true) {
            return null;
        }

        return $token;
    }

    private function createToken(
        Server $server,
        ?User $actor,
        ?string $label,
        ?CarbonInterface $expiresAt,
        ?string $rotatedFromId = null,
    ): IssuedToken {
        $plain = $this->generatePlainText();

        $token = $server->tokens()->create([
            'token_hash' => $this->hash($plain),
            'token_prefix' => substr($plain, 0, 8),
            'label' => $label,
            'expires_at' => $expiresAt,
            'rotated_from_id' => $rotatedFromId,
            'created_by' => $actor?->id,
        ]);

        return new IssuedToken($plain, $token);
    }

    private function currentToken(Server $server): ?ServerToken
    {
        return $server->tokens()
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function generatePlainText(): string
    {
        $encoded = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return 'noc_'.$encoded;
    }

    private function hash(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }

    /**
     * @return array{server_id: string, token_id: string, token_prefix: string}
     */
    private function auditMeta(ServerToken $token): array
    {
        return [
            'server_id' => $token->server_id,
            'token_id' => $token->getKey(),
            'token_prefix' => $token->token_prefix,
        ];
    }
}
