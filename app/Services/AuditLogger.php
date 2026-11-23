<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * Opaque identifier keys that must survive redaction.
     *
     * The spec requires the server, token and actor identifiers (including
     * the 8-character token prefix) in logs; they are handles, not secrets.
     *
     * @var list<string>
     */
    private const IDENTIFIER_KEYS = [
        'server_id',
        'token_id',
        'token_prefix',
        'target_id',
        'actor_id',
        'user_id',
    ];

    private const REDACTED = '[redacted]';

    /**
     * Bounded client metadata. The `ip` column is VARCHAR(45) (IPv6 max) and
     * the user agent is capped at 1 KiB so a hostile header cannot inflate the
     * append-only audit table or fail a driver with a column limit.
     */
    private const IP_MAX_LENGTH = 45;

    private const USER_AGENT_MAX_LENGTH = 1024;

    /**
     * Write an append-only audit event for the current request actor.
     *
     * The actor is never inferred from the subject. Callers either run after
     * authentication or pass the actor explicitly (for example the Logout
     * listener, where the guard has already been cleared).
     *
     * Metadata must never contain secret values or query text. Known
     * sensitive keys are redacted defensively before the row is written.
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, ?Model $subject = null, array $meta = [], ?int $actorId = null): AuditEvent
    {
        $request = app()->bound('request') ? app('request') : null;

        return AuditEvent::query()->create([
            'user_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'target_type' => $subject?->getMorphClass(),
            'target_id' => $subject?->getKey(),
            'ip' => $this->bounded($request?->ip(), self::IP_MAX_LENGTH),
            'user_agent' => $this->bounded($request?->userAgent(), self::USER_AGENT_MAX_LENGTH),
            'meta_json' => $meta === [] ? null : $this->redact($meta),
        ]);
    }

    private function bounded(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Recursively replace values stored under sensitive keys.
     *
     * Redaction is whitelist-first: opaque identifier keys (see
     * {@see self::IDENTIFIER_KEYS}) pass through unchanged because the spec
     * requires them in logs. Only then does redaction apply: when the array
     * declares a real boolean `is_secret`, the `value`/`old_value`/
     * `new_value` entries are redacted, and `is_*`/`has_*` flags are only
     * preserved when they hold a real boolean. Every remaining key matching
     * `password|secret|query|token` is redacted.
     *
     * @param  array<array-key, mixed>  $meta
     * @return array<array-key, mixed>
     */
    private function redact(array $meta): array
    {
        $redacted = [];
        $isSecret = ($meta['is_secret'] ?? false) === true;

        foreach ($meta as $key => $value) {
            if (is_string($key) && in_array($key, self::IDENTIFIER_KEYS, true)) {
                $redacted[$key] = $value;

                continue;
            }

            if ($isSecret && in_array($key, ['value', 'old_value', 'new_value'], true)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            if (is_string($key) && (str_starts_with($key, 'is_') || str_starts_with($key, 'has_'))) {
                $redacted[$key] = is_bool($value) ? $value : self::REDACTED;

                continue;
            }

            if (is_string($key) && preg_match('/(password|secret|query|token)/i', $key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }
}
