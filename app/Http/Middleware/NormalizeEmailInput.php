<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normalize the email field before Fortify's password-reset broker reads it.
 *
 * Login and account creation lower-case and trim addresses, but the reset
 * broker looks the address up directly. Without this, a user who types their
 * address with different casing cannot request or complete a reset.
 */
class NormalizeEmailInput
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }

        return $next($request);
    }
}
