<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates feed-admin actions (credential edits, manual syncs, flagged-
 * offering resolution) behind a shared passphrase held in the session.
 *
 * A signed-in feed administrator ({@see \App\Models\User::isAdmin()})
 * skips the gate entirely and is never prompted for the passphrase.
 */
class FeedAdminPassphraseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAdmin() === true) {
            return $next($request);
        }

        if ($request->session()->get('feed_admin_authenticated') === true) {
            return $next($request);
        }

        // Remember where the admin was heading so we can bounce them back
        // after they enter the passphrase. GET requests only — a POST
        // target is not meaningfully resumable.
        if ($request->isMethod('GET')) {
            $request->session()->put('feed_admin_return_url', $request->fullUrl());
        }

        return redirect()
            ->route('admin.unlock')
            ->with('warning', 'Enter the feed admin passphrase to continue.');
    }
}
