<?php

namespace App\Support;

final class Realtime
{
    public static function enabled(): bool
    {
        return (bool) config('noc.realtime.enabled', false);
    }

    public static function emit(object $event): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            event($event);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
