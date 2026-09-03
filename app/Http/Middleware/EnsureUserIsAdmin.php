<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to signed-in feed administrators.
 *
 * Guests are bounced to the login screen; an authenticated non-admin is
 * refused with a 403 (they may still view market data elsewhere).
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        abort_unless($user->isAdmin(), 403, 'This area is limited to feed administrators.');

        return $next($request);
    }
}
