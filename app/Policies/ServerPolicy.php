<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Server $server): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canOperate();
    }

    public function update(User $user, Server $server): bool
    {
        return $user->canOperate();
    }

    public function delete(User $user, Server $server): bool
    {
        return $user->canOperate();
    }

    public function manageTokens(User $user): bool
    {
        return $user->canOperate();
    }

    public function viewSnapshot(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }

    public function purge(User $user, Server $server): bool
    {
        return $user->isAdmin();
    }
}
