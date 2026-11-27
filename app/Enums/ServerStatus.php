<?php

namespace App\Enums;

enum ServerStatus: string
{
    case NeverSeen = 'never_seen';
    case Online = 'online';
    case Stale = 'stale';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::NeverSeen => 'Never seen',
            self::Online => 'Online',
            self::Stale => 'Stale',
            self::Offline => 'Offline',
        };
    }
}
