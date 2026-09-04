<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Distributor;
use App\Services\Ammunition\SupplyReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public, token-authorized JSON feed of headline supply-report metrics
 * for external syndication — firehole.com/arms/2026-supply/ (WordPress /
 * ThemeCo X-Pro Cornerstone Looper / WP Shortcodes) and research
 * assistants.
 *
 * Authentication is handled entirely by the `supply_report.api_key`
 * route middleware ({@see \App\Http\Middleware\EnsureValidSupplyReportApiKey}):
 * `?api_key=...` or an `Authorization: Bearer ...` header, checked
 * against `config('services.reports.api_key')`.
 */
class PublicSupplyReportController extends Controller
{
    /** Cache key the assembled summary is stored under. */
    public const CACHE_KEY = 'api_supply_summary_v1';

    /** How long one generated summary is cached and reused. */
    public const CACHE_TTL_SECONDS = 600;

    public function summary(SupplyReportQueryService $query): JsonResponse
    {
        $payload = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () use ($query) {
            $now = now();

            return [
                'meta' => [
                    'generated_at' => $now->toIso8601String(),
                    'generated_at_human' => $now->timezone('America/New_York')->format('M j, Y g:i A T'),
                    'distributors_active' => Distributor::where('is_active', true)->count(),
                ],
                ...$query->publicApiSummary(),
            ];
        });

        return response()->json($payload);
    }
}
