<?php

namespace App\Policies;

use App\Models\ServerToken;
use App\Models\User;

class ServerTokenPolicy
{
    public function __construct(private readonly ServerPolicy $serverPolicy)
    {
        //
    }

    public function viewAny(User $user): bool
    {
        return $this->serverPolicy->manageTokens($user);
    }

    public function create(User $user): bool
    {
        return $this->serverPolicy->manageTokens($user);
    }

    public function revoke(User $user, ServerToken $token): bool
    {
        return $this->serverPolicy->manageTokens($user);
    }

    public function rotate(User $user, ServerToken $token): bool
    {
        return $this->serverPolicy->manageTokens($user);
    }
}
