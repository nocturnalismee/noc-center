<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
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
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'meta_json' => $meta === [] ? null : $this->redact($meta),
        ]);
    }

    /**
     * Recursively replace values stored under sensitive keys.
     *
     * Redaction is whitelist-based: when an array declares `is_secret`, the
     * `value`/`old_value`/`new_value` entries are redacted. `is_*`/`has_*`
     * flags are only preserved when they hold a real boolean.
     *
     * @param  array<array-key, mixed>  $meta
     * @return array<array-key, mixed>
     */
    private function redact(array $meta): array
    {
        $redacted = [];
        $isSecret = ($meta['is_secret'] ?? false) === true;

        foreach ($meta as $key => $value) {
            if ($isSecret && in_array($key, ['value', 'old_value', 'new_value'], true)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            if (is_string($key) && (str_starts_with($key, 'is_') || str_starts_with($key, 'has_'))) {
                $redacted[$key] = is_bool($value) ? $value : '[redacted]';

                continue;
            }

            if (is_string($key) && preg_match('/(password|secret|token|query)/i', $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }
}
