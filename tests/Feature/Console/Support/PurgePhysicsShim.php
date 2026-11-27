<?php

/**
 * Filesystem-call recorder for `PurgeServer::databaseSize()`.
 *
 * This file intentionally declares functions in `App\Console\Commands`, the
 * command's namespace. PHP resolves an unqualified `clearstatcache()` or
 * `filesize()` call inside a namespace to that namespace first, so the
 * command's calls are recorded here while every other caller keeps using the
 * global functions. The production code always passes an explicit filename, so
 * the query is deterministic.
 *
 * Used by `tests/Feature/Console/PurgeServerVacuumTest.php`.
 */

namespace App\Console\Commands;

final class PhysicsCalls
{
    /** @var list<string> */
    private static array $calls = [];

    private static ?string $cleared = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$cleared = null;
    }

    public static function record(string $call, ?string $path = null): void
    {
        self::$calls[] = $call;

        if ($call === 'clearstatcache' && $path !== null) {
            self::$cleared = $path;
        }
    }

    public static function statCacheTarget(): ?string
    {
        return self::$cleared;
    }

    public static function clearedBeforeReading(): bool
    {
        $clear = array_search('clearstatcache', self::$calls, true);
        $read = array_search('filesize', self::$calls, true);

        return $clear !== false && $read !== false && $clear < $read;
    }
}

function clearstatcache(bool $clearReal = false, ?string $filename = null): void
{
    if ($clearReal && $filename !== null) {
        PhysicsCalls::record('clearstatcache', $filename);
    }

    \clearstatcache($clearReal, $filename);
}

function filesize(string $filename): int|false
{
    PhysicsCalls::record('filesize', $filename);

    return \filesize($filename);
}
