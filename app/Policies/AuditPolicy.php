<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\User;

class AuditPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canOperate();
    }

    public function view(User $user, AuditEvent $event): bool
    {
        return $user->isAdmin();
    }
}
