<?php

namespace App\Services\Ammunition;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\DistributorSkuOverride;
use App\Models\MasterAmmunition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The query + aggregation layer behind the Ammunition Supply Dashboard.
 *
 * Everything the dashboard renders — the paginated master-product rows
 * with their per-distributor offerings, and the context-aware stat
 * cards — is derived here from a single filtered offering query so the
 * headline numbers always agree with the table beneath them.
 */
class SupplyReportQueryService
{
    /** @var array<int, int> */
    public const PER_PAGE_OPTIONS = [25, 50, 100, 250];

    public const DEFAULT_PER_PAGE = 50;

    public const STOCK_STATUSES = ['all', 'in_stock', 'out_of_stock'];

    public const REVIEW_STATUSES = ['all', 'flagged', 'clean'];

    /** Packaging / pack-size buckets for the `packaging` filter. */
    public const PACKAGING_OPTIONS = ['all', 'standard', 'bulk'];

    /** A "standard box" tops out here (covers 20 / 25 / 50-round retail packs). */
    public const PACKAGING_STANDARD_MAX = 50;

    /** "Bulk / case" starts here (100 / 250 / 500 / 1000+ round packs). */
    public const PACKAGING_BULK_MIN = 100;

    /** @var array<int, string> */
    public const SORTABLE = ['manufacturer', 'caliber', 'name', 'best_price', 'best_cpr', 'total_qty'];

    /** Calibers surfaced as one-tap quick-filter chips, in display order. */
    public const FEATURED_CALIBERS = [
        '9mm Luger', '5.56x45mm NATO', '.223 Remington', '.300 AAC Blackout',
        '6.5 Creedmoor', '.308 Winchester', '.45 ACP', '.40 S&W',
        '.357 Magnum', '.38 Special', '10mm Auto', '.22 LR',
    ];

    /** Projectile types surfaced as one-tap quick-filter chips. */
    public const FEATURED_PROJECTILES = [
        'FMJ', 'TMJ', 'JHP', 'HP', 'OTM', 'SP', 'Polymer Tip', 'Monolithic Solid Copper', 'Subsonic', 'Frangible',
    ];

    /**
     * Normalise raw request input into a clean, fully-defaulted filter
     * bag the rest of the service can trust.
     *
     * @return array<string, mixed>
     */
    public function normalizeFilters(Request $request): array
    {
        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $stockStatus = (string) $request->input('stock_status', 'all');
        if (! in_array($stockStatus, self::STOCK_STATUSES, true)) {
            $stockStatus = 'all';
        }

        $review = (string) $request->input('review', 'all');
        if (! in_array($review, self::REVIEW_STATUSES, true)) {
            $review = 'all';
        }

        // Accepts `packaging` (preferred) or the `pack_size` alias:
        // "all" | "standard" | "bulk" | an exact round count | "1000" (== 1000+).
        $packaging = (string) $request->input('packaging', $request->input('pack_size', 'all'));
        if (! in_array($packaging, self::PACKAGING_OPTIONS, true)
            && ! (ctype_digit($packaging) && (int) $packaging > 0)) {
            $packaging = 'all';
        }

        $sortBy = (string) $request->input('sort_by', 'manufacturer');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'manufacturer';
        }

        $sortDir = strtolower((string) $request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $mode = (string) $request->input('distributor_mode', 'include');
        $mode = $mode === 'exclude' ? 'exclude' : 'include';

        return [
            'distributor_ids' => $this->intList($request->input('distributors', [])),
            'distributor_mode' => $mode,
            'calibers' => $this->stringList($request->input('calibers', [])),
            'projectile_types' => $this->stringList($request->input('projectile_types', [])),
            'grain_weights' => $this->intList($request->input('grain_weights', [])),
            'stock_status' => $stockStatus,
            'review' => $review,
            'packaging' => $packaging,
            'min_qty' => max(0, (int) $request->input('min_qty', 0)),
            'search' => trim((string) $request->input('search', '')),
            'per_page' => $perPage,
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
        ];
    }

    /**
     * The base query over distributor offerings joined to their canonical
     * master record, with every attribute + scope filter applied. Used
     * for both the stat aggregates and the paginated master grouping.
     */
    public function baseOfferingQuery(array $filters): Builder
    {
        $query = DistributorProduct::query()
            ->join('master_ammunition', 'master_ammunition.id', '=', 'distributor_products.master_ammunition_id')
            // 1:1 by the (distributor_id, distributor_sku) unique key, so
            // the reviewer-confirmed count is available to the pricing
            // aggregates without inflating any COUNT().
            ->leftJoin('distributor_sku_overrides', function ($join) {
                $join->on('distributor_sku_overrides.distributor_id', '=', 'distributor_products.distributor_id')
                    ->on('distributor_sku_overrides.distributor_sku', '=', 'distributor_products.distributor_sku');
            })
            ->where('master_ammunition.is_tracked_in_report', true)
            // Reviewer-dismissed offerings are held out of every dashboard
            // figure — the flagged view, the rollups and the stat cards.
            ->where('distributor_products.is_ignored', false)
            ->when($filters['calibers'], fn ($q, $v) => $q->whereIn('master_ammunition.caliber', $v))
            ->when($filters['projectile_types'], fn ($q, $v) => $q->whereIn('master_ammunition.bullet_type', $v))
            ->when($filters['grain_weights'], fn ($q, $v) => $q->whereIn('master_ammunition.bullet_weight_gr', $v))
            ->when(
                $filters['distributor_ids'] && $filters['distributor_mode'] === 'include',
                fn ($q) => $q->whereIn('distributor_products.distributor_id', $filters['distributor_ids']),
            )
            ->when(
                $filters['distributor_ids'] && $filters['distributor_mode'] === 'exclude',
                fn ($q) => $q->whereNotIn('distributor_products.distributor_id', $filters['distributor_ids']),
            )
            ->when($filters['stock_status'] === 'in_stock', fn ($q) => $q->where('distributor_products.is_in_stock', true))
            ->when($filters['stock_status'] === 'out_of_stock', fn ($q) => $q->where('distributor_products.is_in_stock', false))
            ->when($filters['review'] === 'flagged', fn ($q) => $q->where('distributor_products.needs_review', true))
            ->when($filters['review'] === 'clean', fn ($q) => $q->where('distributor_products.needs_review', false))
            ->when($filters['min_qty'] > 0, fn ($q) => $q->where('distributor_products.quantity_available', '>=', $filters['min_qty']))
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $like = '%'.addcslashes($filters['search'], '%_\\').'%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('distributor_products.raw_upc', 'like', $like)
                        ->orWhere('distributor_products.distributor_sku', 'like', $like)
                        ->orWhere('distributor_products.raw_mfr_part_number', 'like', $like)
                        ->orWhere('master_ammunition.upc', 'like', $like)
                        ->orWhere('master_ammunition.mfr_part_number', 'like', $like)
                        ->orWhere('master_ammunition.manufacturer', 'like', $like)
                        ->orWhere('master_ammunition.name', 'like', $like);
                });
            });

        $this->applyPackagingFilter($query, (string) ($filters['packaging'] ?? 'all'));

        return $query;
    }

    /** The effective rounds-per-unit SQL: offering count → override → master. */
    private function effectiveRoundsExpr(): string
    {
        return 'COALESCE(distributor_products.round_count, '
            .'NULLIF(distributor_sku_overrides.round_count, 0), master_ammunition.rounds_per_box)';
    }

    /**
     * Narrow the offering query by pack size, judged on the effective
     * rounds-per-unit. "standard" is <= 50 (20 / 25 / 50-round retail
     * boxes, including a .22 LR 50-round box); "bulk" is >= 100 (100 /
     * 250 / 500 / 1000+ cases and .22 LR bricks). A numeric value is an
     * exact match, except "1000" which means 1000 or more.
     */
    private function applyPackagingFilter(Builder $query, string $packaging): void
    {
        if ($packaging === '' || $packaging === 'all') {
            return;
        }

        $rounds = $this->effectiveRoundsExpr();

        match (true) {
            $packaging === 'standard' => $query
                ->whereRaw("{$rounds} > 0")
                ->whereRaw("{$rounds} <= ?", [self::PACKAGING_STANDARD_MAX]),
            $packaging === 'bulk' => $query->whereRaw("{$rounds} >= ?", [self::PACKAGING_BULK_MIN]),
            ctype_digit($packaging) && (int) $packaging >= 1000 => $query->whereRaw("{$rounds} >= ?", [1000]),
            ctype_digit($packaging) => $query->whereRaw("{$rounds} = ?", [(int) $packaging]),
            default => null,
        };
    }

    /**
     * Aggregated, context-aware statistics for the stat-card row. Every
     * figure is computed strictly against the active filtered dataset.
     *
     * @return array<string, mixed>
     */
    public function stats(array $filters): array
    {
        // Offerings flagged `needs_review` carry a distrusted price and are
        // held out of every priced aggregate so a bad parse never distorts
        // the market spread; they still count toward SKU / pipeline totals.
        // Effective rounds-per-unit, most trusted first: the offering's
        // own confirmed count, then its SKU override, and only then the
        // master's box count (which a case SKU can have poisoned).
        $rounds = 'COALESCE(distributor_products.round_count, '
            .'NULLIF(distributor_sku_overrides.round_count, 0), master_ammunition.rounds_per_box)';
        $priced = 'distributor_products.wholesale_price > 0 AND NOT distributor_products.needs_review';
        $pricedCpr = $priced." AND {$rounds} > 0";
        $cprExpr = "distributor_products.wholesale_price * 1.0 / {$rounds}";

        $agg = $this->baseOfferingQuery($filters)->toBase()
            ->selectRaw('COUNT(*) as offer_count')
            ->selectRaw('COUNT(DISTINCT distributor_products.master_ammunition_id) as sku_count')
            ->selectRaw('COALESCE(SUM(distributor_products.quantity_available * master_ammunition.rounds_per_box), 0) as pipeline_rounds')
            ->selectRaw('SUM(CASE WHEN distributor_products.needs_review THEN 1 ELSE 0 END) as review_count')
            ->selectRaw("MIN(CASE WHEN {$priced} THEN distributor_products.wholesale_price END) as min_box")
            ->selectRaw("AVG(CASE WHEN {$priced} THEN distributor_products.wholesale_price END) as avg_box")
            ->selectRaw("MAX(CASE WHEN {$priced} THEN distributor_products.wholesale_price END) as max_box")
            ->selectRaw("MIN(CASE WHEN {$pricedCpr} THEN {$cprExpr} END) as min_cpr")
            ->selectRaw("AVG(CASE WHEN {$pricedCpr} THEN {$cprExpr} END) as avg_cpr")
            ->selectRaw("MAX(CASE WHEN {$pricedCpr} THEN {$cprExpr} END) as max_cpr")
            ->first();

        $totalSkus = (int) ($agg->sku_count ?? 0);

        $inStockSkus = (int) $this->baseOfferingQuery($filters)->toBase()
            ->where('distributor_products.is_in_stock', true)
            ->distinct()
            ->count('distributor_products.master_ammunition_id');

        $outOfStockSkus = max(0, $totalSkus - $inStockSkus);
        $pipelineRounds = (int) round((float) ($agg->pipeline_rounds ?? 0));

        return [
            'scope_label' => $this->scopeLabel($filters),
            'total_skus' => $totalSkus,
            'in_stock_skus' => $inStockSkus,
            'out_of_stock_skus' => $outOfStockSkus,
            'in_stock_pct' => $totalSkus > 0 ? round($inStockSkus / $totalSkus * 100, 1) : 0.0,
            'needs_review' => (int) ($agg->review_count ?? 0),
            'offer_count' => (int) ($agg->offer_count ?? 0),
            'pipeline_rounds' => $pipelineRounds,
            'pipeline_boxes' => (int) $this->baseOfferingQuery($filters)->toBase()->sum('distributor_products.quantity_available'),
            'box_price' => [
                'min' => $this->asFloat($agg->min_box),
                'avg' => $this->asFloat($agg->avg_box, 2),
                'max' => $this->asFloat($agg->max_box),
            ],
            'cpr' => [
                'min' => $this->asFloat($agg->min_cpr, 4),
                'avg' => $this->asFloat($agg->avg_cpr, 4),
                'max' => $this->asFloat($agg->max_cpr, 4),
            ],
        ];
    }

    /**
     * The paginated master-product rows for the presentation table, each
     * decorated with its best price / CPR, aggregate quantity, active
     * distributor badges and the full offering list for the accordion.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $dir = $filters['sort_dir'] === 'desc' ? 'desc' : 'asc';

        // Aggregate expressions are declared once and reused verbatim in
        // both the SELECT and the ORDER BY. PostgreSQL rejects an ORDER BY
        // that wraps a SELECT alias in an expression on a grouped query
        // (SQLSTATE 42703 / 42803 "must appear in the GROUP BY clause or
        // be used in an aggregate function"), so the ordering has to
        // repeat the aggregate itself rather than lean on `agg_*` aliases.
        // `needs_review` offerings are excluded from the best-price / best-CPR
        // aggregates (their price is distrusted) but still contribute to the
        // aggregate quantity.
        $rounds = 'COALESCE(distributor_products.round_count, '
            .'NULLIF(distributor_sku_overrides.round_count, 0), master_ammunition.rounds_per_box)';
        $bestPriceExpr = 'MIN(CASE WHEN distributor_products.wholesale_price > 0 AND NOT distributor_products.needs_review THEN distributor_products.wholesale_price END)';
        $bestCprExpr = "MIN(CASE WHEN distributor_products.wholesale_price > 0 AND NOT distributor_products.needs_review AND {$rounds} > 0 THEN distributor_products.wholesale_price * 1.0 / {$rounds} END)";
        $totalQtyExpr = 'COALESCE(SUM(distributor_products.quantity_available), 0)';

        $grouped = $this->baseOfferingQuery($filters)->toBase()
            ->select('distributor_products.master_ammunition_id as mid')
            ->selectRaw("{$bestPriceExpr} as agg_best_price")
            ->selectRaw("{$bestCprExpr} as agg_best_cpr")
            ->selectRaw("{$totalQtyExpr} as agg_total_qty")
            ->addSelect('master_ammunition.manufacturer as m_manufacturer')
            ->addSelect('master_ammunition.caliber as m_caliber')
            ->addSelect('master_ammunition.name as m_name')
            ->groupBy(
                'distributor_products.master_ammunition_id',
                'master_ammunition.manufacturer',
                'master_ammunition.caliber',
                'master_ammunition.name',
            );

        // The leading `(expr) is null` clause keeps NULL prices / CPRs at
        // the end for both sort directions (portable across PostgreSQL,
        // SQLite and MySQL; avoids relying on NULLS LAST support).
        match ($filters['sort_by']) {
            'caliber' => $grouped->orderBy('m_caliber', $dir)->orderBy('m_manufacturer')->orderBy('m_name'),
            'name' => $grouped->orderBy('m_name', $dir)->orderBy('m_manufacturer'),
            'best_price' => $grouped
                ->orderByRaw("({$bestPriceExpr}) is null")
                ->orderByRaw("({$bestPriceExpr}) {$dir}")
                ->orderBy('m_manufacturer'),
            'best_cpr' => $grouped
                ->orderByRaw("({$bestCprExpr}) is null")
                ->orderByRaw("({$bestCprExpr}) {$dir}")
                ->orderBy('m_manufacturer'),
            'total_qty' => $grouped
                ->orderByRaw("({$totalQtyExpr}) {$dir}")
                ->orderBy('m_manufacturer'),
            default => $grouped->orderBy('m_manufacturer', $dir)->orderBy('m_caliber')->orderBy('m_name'),
        };

        /** @var LengthAwarePaginator $paginator */
        $paginator = $grouped->paginate($filters['per_page'])->withQueryString();

        $orderedIds = collect($paginator->items())->pluck('mid')->all();

        $masters = MasterAmmunition::query()
            ->whereIn('id', $orderedIds ?: [0])
            ->with(['distributorProducts' => function ($q) use ($filters) {
                $this->applyOfferingScope($q, $filters);
                $q->with('distributor')->orderBy('wholesale_price');
            }])
            ->get()
            ->keyBy('id');

        $overrides = $this->overrideCountsFor($masters);

        $rows = collect($orderedIds)
            ->map(fn ($id) => $masters->get($id))
            ->filter()
            ->each(fn (MasterAmmunition $master) => $this->decorate($master, $overrides))
            ->values();

        $paginator->setCollection($rows);

        return $paginator;
    }

    /**
     * Context-aware ("cascading") facet options for the filter accordions.
     *
     * Each attribute facet is computed against a deliberately partial view
     * of the active filters so the options narrow as the user drills in
     * but a facet never hides its own already-picked values:
     *
     *   - calibers        ← distributor + stock scope
     *   - projectile_types ← distributor + stock + caliber scope
     *   - grain_weights   ← distributor + stock + caliber + projectile scope
     *
     * Every value is returned with the distinct master-SKU count that
     * would remain if it were selected, so the badges reflect the cascade.
     *
     * @return array{
     *     distributors: \Illuminate\Support\Collection<int, Distributor>,
     *     calibers: array<string, int>,
     *     projectile_types: array<string, int>,
     *     grain_weights: array<int, int>,
     *     packaging: array{standard: int, bulk: int, by_size: array<int, int>},
     *     per_page_options: array<int, int>
     * }
     */
    public function facets(array $filters): array
    {
        return [
            'distributors' => Distributor::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'calibers' => $this->facetCounts(
                $filters,
                'master_ammunition.caliber',
                ['calibers', 'projectile_types', 'grain_weights', 'min_qty', 'search'],
            ),
            'projectile_types' => $this->facetCounts(
                $filters,
                'master_ammunition.bullet_type',
                ['projectile_types', 'grain_weights', 'min_qty', 'search'],
            ),
            'grain_weights' => $this->facetCounts(
                $filters,
                'master_ammunition.bullet_weight_gr',
                ['grain_weights', 'min_qty', 'search'],
                true,
            ),
            'packaging' => $this->packagingFacetCounts($filters),
            'per_page_options' => self::PER_PAGE_OPTIONS,
        ];
    }

    /** Exact round counts surfaced as granular packaging quick-filter pills. */
    public const PACKAGING_QUICK_SIZES = [20, 50, 100, 500, 1000];

    /**
     * Distinct master-SKU counts for the packaging chips — the Standard
     * / Bulk buckets and each granular quick-size pill — evaluated
     * against the active scope but with the packaging filter itself
     * neutralised so a chip never collapses its own count.
     *
     * `by_size` is keyed by the exact round count; the `1000` key is
     * "1000 or more" to match {@see applyPackagingFilter()}.
     *
     * @return array{standard: int, bulk: int, by_size: array<int, int>}
     */
    private function packagingFacetCounts(array $filters): array
    {
        $scoped = array_merge($filters, ['packaging' => 'all']);
        $rounds = $this->effectiveRoundsExpr();
        $mid = 'distributor_products.master_ammunition_id';

        $query = $this->baseOfferingQuery($scoped)->toBase()
            ->selectRaw(
                "COUNT(DISTINCT CASE WHEN {$rounds} > 0 AND {$rounds} <= ? THEN {$mid} END) as standard_count",
                [self::PACKAGING_STANDARD_MAX],
            )
            ->selectRaw(
                "COUNT(DISTINCT CASE WHEN {$rounds} >= ? THEN {$mid} END) as bulk_count",
                [self::PACKAGING_BULK_MIN],
            );

        foreach (self::PACKAGING_QUICK_SIZES as $size) {
            $operator = $size >= 1000 ? '>=' : '=';
            $query->selectRaw(
                "COUNT(DISTINCT CASE WHEN {$rounds} {$operator} ? THEN {$mid} END) as size_{$size}",
                [$size],
            );
        }

        $row = $query->first();

        $bySize = [];
        foreach (self::PACKAGING_QUICK_SIZES as $size) {
            $bySize[$size] = (int) ($row->{"size_{$size}"} ?? 0);
        }

        return [
            'standard' => (int) ($row->standard_count ?? 0),
            'bulk' => (int) ($row->bulk_count ?? 0),
            'by_size' => $bySize,
        ];
    }

    /**
     * Distinct master-SKU count per distinct value of $column, evaluated
     * against the filter bag with $reset keys neutralised (this is what
     * makes the facet "cascade" rather than collapse to a single value).
     *
     * @param  array<int, string>  $reset  Filter keys to ignore for this facet.
     * @return array<array-key, int> Ordered value => count.
     */
    private function facetCounts(array $filters, string $column, array $reset, bool $castKeyToInt = false): array
    {
        $scoped = $filters;

        foreach ($reset as $key) {
            $scoped[$key] = match ($key) {
                'search' => '',
                'min_qty' => 0,
                'packaging' => 'all',
                default => [],
            };
        }

        $rows = $this->baseOfferingQuery($scoped)->toBase()
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderBy($column)
            ->selectRaw("{$column} as facet_value")
            ->selectRaw('COUNT(DISTINCT distributor_products.master_ammunition_id) as facet_count')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $key = $castKeyToInt ? (int) $row->facet_value : (string) $row->facet_value;
            $out[$key] = (int) $row->facet_count;
        }

        return $out;
    }

    /**
     * Human-readable label for the active distributor selection, e.g.
     * "All Distributors", "RSR Group + Lipseys" or "All except Zanders".
     */
    public function scopeLabel(array $filters): string
    {
        $ids = $filters['distributor_ids'];

        if (! $ids) {
            return 'All Distributors';
        }

        $names = Distributor::query()->whereIn('id', $ids)->orderBy('name')->pluck('name');

        if ($filters['distributor_mode'] === 'exclude') {
            return $names->count() <= 3
                ? 'All except '.$names->implode(' & ')
                : 'All except '.$names->count().' distributors';
        }

        return $names->count() <= 3
            ? $names->implode(' + ')
            : $names->count().' distributors';
    }

    /* ------------------------------------------------------------------ */

    /**
     * Apply just the offering-level scope (distributor + stock + min qty)
     * to an eager-load constraint, mirroring {@see baseOfferingQuery()}.
     */
    private function applyOfferingScope(object $q, array $filters): void
    {
        $q->when(
            $filters['distributor_ids'] && $filters['distributor_mode'] === 'include',
            fn ($sub) => $sub->whereIn('distributor_id', $filters['distributor_ids']),
        )->when(
            $filters['distributor_ids'] && $filters['distributor_mode'] === 'exclude',
            fn ($sub) => $sub->whereNotIn('distributor_id', $filters['distributor_ids']),
        )->where('is_ignored', false)
            ->when($filters['stock_status'] === 'in_stock', fn ($sub) => $sub->where('is_in_stock', true))
            ->when($filters['stock_status'] === 'out_of_stock', fn ($sub) => $sub->where('is_in_stock', false))
            ->when($filters['review'] === 'flagged', fn ($sub) => $sub->where('needs_review', true))
            ->when($filters['review'] === 'clean', fn ($sub) => $sub->where('needs_review', false))
            ->when($filters['min_qty'] > 0, fn ($sub) => $sub->where('quantity_available', '>=', $filters['min_qty']));
    }

    /**
     * @param  array<string, int>  $overrideCounts  "{distributor_id}:{sku}" => confirmed round count
     */
    private function decorate(MasterAmmunition $master, array $overrideCounts = []): void
    {
        $listings = $master->distributorProducts;

        // Best price / CPR only ever come from trustworthy (non-flagged) rows.
        $trustworthy = $listings->reject(fn ($l) => (bool) $l->needs_review);
        $priced = $trustworthy->filter(fn ($l) => (float) $l->wholesale_price > 0);

        $best = ($priced->isNotEmpty() ? $priced : $trustworthy)->sortBy('wholesale_price')->first();
        $roundsPerBox = (int) $master->rounds_per_box;

        // Effective rounds-per-unit for a listing, most trusted first:
        // the offering's own confirmed count, then its SKU override, then
        // the master's box count.
        $effectiveRounds = function ($l) use ($roundsPerBox, $overrideCounts): int {
            if ((int) $l->round_count > 0) {
                return (int) $l->round_count;
            }

            $override = $overrideCounts[$l->distributor_id.':'.$l->distributor_sku] ?? 0;

            return $override > 0 ? $override : $roundsPerBox;
        };
        $bestRounds = $best ? $effectiveRounds($best) : 0;

        $master->best_price_per_box = $best ? (float) $best->wholesale_price : null;
        $master->best_price_per_round = ($best && $bestRounds > 0 && (float) $best->wholesale_price > 0)
            ? round((float) $best->wholesale_price / $bestRounds, 4)
            : null;
        $master->best_distributor_name = $best?->distributor?->name;
        $master->total_quantity_available = (int) $listings->sum('quantity_available');
        $master->in_stock = $listings->contains(fn ($l) => $l->is_in_stock && $l->quantity_available > 0);
        $master->listing_count = $listings->count();
        $master->review_count = $listings->filter(fn ($l) => (bool) $l->needs_review)->count();

        $master->distributor_badges = $listings
            ->map(fn ($l) => ['name' => $l->distributor?->name, 'in_stock' => (bool) $l->is_in_stock])
            ->filter(fn ($b) => $b['name'] !== null)
            ->unique('name')
            ->sortBy('name')
            ->values();

        $master->offerings = $listings->map(fn ($l) => [
            'id' => $l->id,
            'distributor' => $l->distributor?->name,
            'sku' => $l->distributor_sku,
            'raw_description' => $l->raw_description,
            'dealer_cost' => (float) $l->wholesale_price,
            'rounds_per_unit' => $effectiveRounds($l),
            'cpr' => ($effectiveRounds($l) > 0 && (float) $l->wholesale_price > 0)
                ? round((float) $l->wholesale_price / $effectiveRounds($l), 4)
                : null,
            'qty' => (int) $l->quantity_available,
            'in_stock' => (bool) $l->is_in_stock,
            'needs_review' => (bool) $l->needs_review,
            'review_reason' => $l->review_reason,
            'updated_at' => $l->last_feed_update_at,
        ])->values();
    }

    /**
     * Confirmed round counts from the SKU-override ledger for the given
     * masters' listings, keyed "{distributor_id}:{sku}".
     *
     * @param  \Illuminate\Support\Collection<int, MasterAmmunition>  $masters
     * @return array<string, int>
     */
    private function overrideCountsFor($masters): array
    {
        $pairs = $masters
            ->flatMap(fn (MasterAmmunition $m) => $m->distributorProducts)
            ->map(fn ($l) => [$l->distributor_id, (string) $l->distributor_sku])
            ->filter(fn ($p) => $p[0] !== null && $p[1] !== '')
            ->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        return DistributorSkuOverride::query()
            ->where('is_ignored', false)
            ->where('round_count', '>', 0)
            ->whereIn('distributor_id', $pairs->pluck(0)->unique()->all())
            ->whereIn('distributor_sku', $pairs->pluck(1)->unique()->all())
            ->get(['distributor_id', 'distributor_sku', 'round_count'])
            ->mapWithKeys(fn ($o) => [$o->distributor_id.':'.$o->distributor_sku => (int) $o->round_count])
            ->all();
    }

    /**
     * @param  mixed  $value
     * @return array<int, int>
     */
    private function intList($value): array
    {
        return collect(is_array($value) ? $value : explode(',', (string) $value))
            ->map(fn ($v) => (int) trim((string) $v))
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function stringList($value): array
    {
        return collect(is_array($value) ? $value : explode(',', (string) $value))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function asFloat($value, int $precision = 2): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
