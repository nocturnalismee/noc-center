<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Session and remember-me invalidation for account security changes.
 *
 * The application stores sessions in the database by default (spec §15), so
 * deleting the rows for a user signs their other browsers out. The delete is
 * attempted even when another driver is active: the `sessions` table may
 * still hold rows from a previous configuration, and removing them is
 * harmless. File/redis stores cannot be enumerated portably, which is the
 * documented "where feasible" boundary for self-service changes.
 */
class UserSessions
{
    public function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    /**
     * Delete the user's stored sessions. Pass the current session id to keep
     * the browser that performed a self-service change signed in.
     */
    public function purge(User $user, ?string $exceptSessionId = null): int
    {
        $table = (string) config('session.table', 'sessions');

        if (! Schema::hasTable($table)) {
            return 0;
        }

        $connection = config('session.connection') ?: config('database.default');

        return DB::connection($connection)
            ->table($table)
            ->where('user_id', $user->getKey())
            ->when(
                $exceptSessionId !== null && $exceptSessionId !== '',
                static fn ($query) => $query->where('id', '!=', $exceptSessionId),
            )
            ->delete();
    }
}
