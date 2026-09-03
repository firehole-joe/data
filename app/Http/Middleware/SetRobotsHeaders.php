<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * data.firehole.com is an internal operations tool: every response
 * carries an aggressive `X-Robots-Tag` so that any crawler which ignores
 * robots.txt still refuses to index, follow, archive or snippet it.
 */
class SetRobotsHeaders
{
    public const DIRECTIVE = 'noindex, nofollow, noarchive, nosnippet';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', self::DIRECTIVE);

        return $response;
    }
}
