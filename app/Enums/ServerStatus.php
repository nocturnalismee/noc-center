<?php

namespace App\Enums;

enum ServerStatus: string
{
    case NeverSeen = 'never_seen';
    case Online = 'online';
    case Stale = 'stale';
    case Offline = 'offline';
}
