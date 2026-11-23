<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force a user with an administrator-set password to choose a new one.
 *
 * The redirect target is the profile page, which is itself allowed through,
 * so there is no loop. Logout, the Fortify password endpoints and Livewire's
 * own update endpoints stay reachable: without the Livewire exemption every
 * form submission from the profile page would be redirected back to it.
 */
class EnsurePasswordChanged
{
    /**
     * Route names reachable while the flag is set.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'settings.profile',
        'logout',
        'user-password.update',
        'password.confirm',
        'password.confirmation',
        'password.email',
        'password.request',
        'password.reset',
        'password.update',
        'two-factor.login',
        'two-factor.login.store',
        'two-factor.challenge',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($this->allowed($request)) {
            return $next($request);
        }

        return redirect()->route('settings.profile');
    }

    private function allowed(Request $request): bool
    {
        // Livewire 4 publishes its endpoints under a hashed `livewire-*`
        // prefix; earlier versions use `livewire/`. Both must pass.
        if (str_starts_with($request->path(), 'livewire')) {
            return true;
        }

        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWED_ROUTES, true);
    }
}
