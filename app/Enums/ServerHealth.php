<?php

namespace App\Enums;

enum ServerHealth: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';
}
