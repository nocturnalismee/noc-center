<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force a user with an administrator-set password to choose a new one.
 *
 * The redirect target is the profile page, which is itself allowed through,
 * so there is no loop. Logout, the Fortify password endpoints stay reachable.
 *
 * Livewire is deliberately narrow rather than blanket-exempt: GET asset
 * requests pass, but an update is allowed only when every component in the
 * payload is `settings.profile`. Otherwise a flagged user could keep using
 * another already-open tab (users, alert settings, or any other component)
 * without changing the password.
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

    private const PROFILE_COMPONENT = 'settings.profile';

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
        if (str_starts_with($request->path(), 'livewire')) {
            return $this->livewireAllowed($request);
        }

        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWED_ROUTES, true);
    }

    /**
     * Livewire 4 publishes its endpoints under a hashed `livewire-*` prefix;
     * earlier versions use `livewire/`. GET requests are framework asset
     * fetches. Any other request must carry only `settings.profile`
     * components; malformed JSON or a missing payload is denied.
     */
    private function livewireAllowed(Request $request): bool
    {
        if ($request->isMethod('GET')) {
            return true;
        }

        if (! str_ends_with($request->path(), '/update')) {
            return false;
        }

        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                return false;
            }

            $snapshot = $component['snapshot'] ?? null;

            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);

                if (! is_array($snapshot)) {
                    return false;
                }
            }

            if (! is_array($snapshot)) {
                return false;
            }

            $memo = $snapshot['memo'] ?? null;

            if (! is_array($memo) || ($memo['name'] ?? null) !== self::PROFILE_COMPONENT) {
                return false;
            }
        }

        return true;
    }
}
