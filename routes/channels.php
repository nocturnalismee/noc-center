<?php

use App\Models\Alert;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('fleet', function (User $user): bool {
    return $user->is_active;
});

Broadcast::channel('alerts', function (User $user): bool {
    return $user->is_active && Gate::allows('viewAny', Alert::class);
});

Broadcast::channel('server.{server}', function (User $user, Server $server): bool {
    return $user->is_active && Gate::allows('view', $server);
});

Broadcast::channel('alert.{alert}', function (User $user, Alert $alert): bool {
    return $user->is_active && Gate::allows('view', $alert);
});
