<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject a session whose account has been deactivated since sign-in.
 *
 * Fortify already refuses a fresh login for an inactive user; this closes the
 * existing-session hole. The account's remember token is rotated, the session
 * is invalidated, and the browser is sent to the sign-in screen. Livewire and
 * other JSON callers get a 403 so a component request cannot follow a redirect
 * to an HTML page.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson() || str_starts_with($request->path(), 'livewire')) {
            return response()->json(['message' => 'This account is inactive.'], 403);
        }

        return redirect()->route('login')->withErrors([
            'email' => 'This account is inactive.',
        ]);
    }
}
