<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandProvenance;
use App\Models\Distributor;
use App\Services\Ammunition\SupplyReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    /** Prefix for the per-query summary cache entries. */
    public const CACHE_KEY = 'api_supply_summary_v1';

    /** How long one generated summary is cached and reused. */
    public const CACHE_TTL_SECONDS = 600;

    public function summary(Request $request, SupplyReportQueryService $query): JsonResponse
    {
        $options = $this->summaryOptions($request);

        // Vary the cache entry by the full query string so a filtered
        // request never returns the unfiltered default (or another
        // filter's) cached payload.
        $cacheKey = self::CACHE_KEY.'_'.md5((string) json_encode($request->query()));

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($query, $options) {
            $now = now();

            return [
                'meta' => [
                    'generated_at' => $now->toIso8601String(),
                    'generated_at_human' => $now->timezone('America/New_York')->format('M j, Y g:i A T'),
                    'distributors_active' => Distributor::where('is_active', true)->count(),
                    'filters' => [
                        'min_stock_units' => $options['min_stock_units'],
                        'provenance_filter' => $options['provenance_filter'],
                        'include_provenance_breakdown' => $options['include_provenance_breakdown'],
                    ],
                ],
                ...$query->publicApiSummary($options),
            ];
        });

        return response()->json($payload);
    }

    /**
     * The full brand → provenance mapping, same token auth as the
     * summary feed.
     */
    public function brandProvenance(): JsonResponse
    {
        $brands = BrandProvenance::query()
            ->orderBy('brand_name')
            ->get(['brand_name', 'provenance', 'notes'])
            ->map(fn (BrandProvenance $row) => [
                'brand' => $row->brand_name,
                'provenance' => $row->provenance,
                'notes' => $row->notes,
            ])
            ->all();

        return response()->json([
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'tiers' => BrandProvenance::TIERS,
                'count' => count($brands),
            ],
            'brands' => $brands,
        ]);
    }

    /**
     * Normalise the three optional query parameters into the option bag
     * {@see SupplyReportQueryService::publicApiSummary()} expects.
     *
     * @return array{min_stock_units: int, provenance_filter: ?string, include_provenance_breakdown: bool}
     */
    private function summaryOptions(Request $request): array
    {
        $provenance = $request->query('provenance_filter');
        $provenance = is_string($provenance) && in_array($provenance, BrandProvenance::TIERS, true)
            ? $provenance
            : null;

        return [
            'min_stock_units' => max(0, (int) $request->query('min_stock_units', 0)),
            'provenance_filter' => $provenance,
            'include_provenance_breakdown' => filter_var(
                $request->query('include_provenance_breakdown', false),
                FILTER_VALIDATE_BOOL,
            ),
        ];
    }
}
