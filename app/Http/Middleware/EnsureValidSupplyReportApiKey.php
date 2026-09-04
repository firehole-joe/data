<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the public supply-report API behind a shared key, accepted
 * either as `?api_key=...` or an `Authorization: Bearer ...` header,
 * checked against `config('services.reports.api_key')`.
 *
 * This is a simple shared secret, not a per-consumer credential — it
 * exists to keep the feed out of search engines and casual scraping,
 * not to authenticate an individual user.
 */
class EnsureValidSupplyReportApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.reports.api_key');
        $provided = (string) ($request->query('api_key') ?? $request->bearerToken() ?? '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Invalid or missing API key.',
            ], 401);
        }

        return $next($request);
    }
}
