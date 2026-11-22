<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * Write an append-only audit event for the current request actor.
     *
     * Metadata must never contain secret values or query text. Known
     * sensitive keys are redacted defensively before the row is written.
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, ?Model $subject = null, array $meta = []): AuditEvent
    {
        $request = app()->bound('request') ? app('request') : null;

        $userId = Auth::id();

        if ($userId === null && $subject instanceof User) {
            $userId = $subject->getKey();
        }

        return AuditEvent::query()->create([
            'user_id' => $userId,
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
     * @param  array<array-key, mixed>  $meta
     * @return array<array-key, mixed>
     */
    private function redact(array $meta): array
    {
        $redacted = [];

        foreach ($meta as $key => $value) {
            $isFlag = is_string($key) && (str_starts_with($key, 'is_') || str_starts_with($key, 'has_'));

            if (! $isFlag && is_string($key) && preg_match('/(password|secret|token|query)/i', $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }
}
