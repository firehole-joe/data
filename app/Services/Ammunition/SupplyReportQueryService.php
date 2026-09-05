<?php

namespace App\Services\Ammunition;

use App\Models\BrandProvenance;
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

    /* ------------------------------------------------------------------ */
    /*  Public supply-summary API (app/Http/Controllers/Api) */
    /* ------------------------------------------------------------------ */

    /**
     * The ball / target-ammunition `bullet_type` values the public
     * supply-summary API counts, applied identically to all five caliber
     * groups so the 2026 Supply Report tracks one consistent
     * training-ammo definition.
     *
     * `FMJ` full metal jacket, `TMJ` total metal / copper-plated target
     * rounds, and `FP` flat-point ball. `SP` (soft point) and `JHP` /
     * `HP` (hollow point) are deliberately excluded — the report is pure
     * target/training ball ammo, not hunting or defensive loads.
     *
     * @var array<int, string>
     */
    public const PUBLIC_API_BALL_BULLET_TYPES = ['FMJ', 'TMJ', 'FP'];

    /**
     * Caliber groups exposed by the public supply-summary API: the
     * canonical `master_ammunition.caliber` value(s) each display label
     * covers. `.223 Remington` is folded into "5.56 NATO" (commercially
     * interchangeable ball loads). Every group uses the same
     * {@see self::PUBLIC_API_BALL_BULLET_TYPES} allow-list.
     *
     * @var array<string, array{calibers: array<int, string>, bullet_types: array<int, string>}>
     */
    public const PUBLIC_API_CALIBER_GROUPS = [
        '9mm Luger' => [
            'calibers' => ['9mm Luger'],
            'bullet_types' => self::PUBLIC_API_BALL_BULLET_TYPES,
        ],
        '5.56 NATO' => [
            'calibers' => ['5.56x45mm NATO', '.223 Remington'],
            'bullet_types' => self::PUBLIC_API_BALL_BULLET_TYPES,
        ],
        '.300 AAC Blackout' => [
            'calibers' => ['.300 AAC Blackout'],
            'bullet_types' => self::PUBLIC_API_BALL_BULLET_TYPES,
        ],
        '.45 ACP' => [
            'calibers' => ['.45 ACP'],
            'bullet_types' => self::PUBLIC_API_BALL_BULLET_TYPES,
        ],
        '.357 Magnum' => [
            'calibers' => ['.357 Magnum'],
            'bullet_types' => self::PUBLIC_API_BALL_BULLET_TYPES,
        ],
    ];

    /**
     * The full payload behind `GET /api/v1/supply-summary`: a standard
     * retail-box (<= 50 rd) breakdown and a bulk/case (>= 100 rd)
     * breakdown for each ball-ammo caliber group in
     * {@see self::PUBLIC_API_CALIBER_GROUPS}, plus a root-level
     * `unclassified_brands` diagnostic.
     *
     * Recognised `$options`:
     *  - `min_stock_units` (int, default 0): drop any offering with
     *    `quantity_available` below this before every count / price.
     *  - `provenance_filter` (?string): one of {@see BrandProvenance::TIERS};
     *    restricts every figure to brands in that tier and excludes
     *    unclassified brands entirely.
     *  - `include_provenance_breakdown` (bool, default false): nest a
     *    `provenance_breakdown` object in each caliber block splitting the
     *    standard-box stats three ways by provenance tier (unclassified
     *    brands fall into none of the three).
     *
     * @param  array<string, mixed>  $options
     * @return array{calibers: array<string, mixed>, bulk_offerings: array<string, mixed>, unclassified_brands: array<int, string>}
     */
    public function publicApiSummary(array $options = []): array
    {
        $minStock = max(0, (int) ($options['min_stock_units'] ?? 0));
        $provenanceFilter = $this->validProvenanceFilter($options['provenance_filter'] ?? null);
        $includeBreakdown = (bool) ($options['include_provenance_breakdown'] ?? false);

        $calibers = [];
        $bulk = [];

        foreach (self::PUBLIC_API_CALIBER_GROUPS as $label => $group) {
            $calibers[$label] = $this->publicStandardBoxSummary(
                $group['calibers'],
                $group['bullet_types'],
                $minStock,
                $provenanceFilter,
                $includeBreakdown,
            );
            $bulk[$label] = $this->publicBulkSummary(
                $group['calibers'],
                $group['bullet_types'],
                $minStock,
                $provenanceFilter,
            );
        }

        return [
            'calibers' => $calibers,
            'bulk_offerings' => $bulk,
            'unclassified_brands' => $this->publicApiUnclassifiedBrands(),
        ];
    }

    /**
     * Only a recognised provenance tier survives; anything else (a typo,
     * an empty string, null) resolves to "no provenance filter".
     */
    private function validProvenanceFilter(mixed $value): ?string
    {
        return is_string($value) && in_array($value, BrandProvenance::TIERS, true) ? $value : null;
    }

    /**
     * The base offering scope for one public-API caliber group: every
     * clean, tracked offering for the given calibers and ball bullet
     * types, left-joined to `brand_provenances` on the brand name so a
     * provenance tier can be filtered or grouped downstream.
     *
     * `$minStock` drops thin inventory; `$provenanceFilter`, when set,
     * both restricts to that tier and (because the match is on a
     * non-null `brand_provenances.provenance`) excludes every
     * unclassified brand.
     *
     * @param  array<int, string>  $calibers
     * @param  array<int, string>  $bulletTypes
     */
    private function publicApiOfferingQuery(
        array $calibers,
        array $bulletTypes,
        int $minStock = 0,
        ?string $provenanceFilter = null,
    ): Builder {
        $query = $this->baseOfferingQuery($this->normalizeFilters(new Request))
            ->leftJoin('brand_provenances', function ($join) {
                $join->whereRaw('LOWER(brand_provenances.brand_name) = LOWER(master_ammunition.manufacturer)');
            })
            ->whereIn('master_ammunition.caliber', $calibers)
            ->whereIn('master_ammunition.bullet_type', $bulletTypes)
            ->where('distributor_products.needs_review', false);

        if ($minStock > 0) {
            $query->where('distributor_products.quantity_available', '>=', $minStock);
        }

        if ($provenanceFilter !== null) {
            $query->where('brand_provenances.provenance', $provenanceFilter);
        }

        return $query;
    }

    /**
     * Standard-box (<= 50 rd) stats for one caliber group: catalog /
     * stock counts across every tracked SKU, and pricing derived only
     * from the in-stock, priced subset. With `$includeBreakdown` a
     * `provenance_breakdown` object is appended (see
     * {@see self::publicProvenanceBreakdown()}).
     *
     * @param  array<int, string>  $calibers
     * @param  array<int, string>  $bulletTypes
     * @return array<string, mixed>
     */
    private function publicStandardBoxSummary(
        array $calibers,
        array $bulletTypes,
        int $minStock,
        ?string $provenanceFilter,
        bool $includeBreakdown,
    ): array {
        $base = $this->publicApiOfferingQuery($calibers, $bulletTypes, $minStock, $provenanceFilter);
        $this->applyPackagingFilter($base, 'standard');

        $summary = $this->standardBoxAggregate($base, withBestValue: true);

        if ($includeBreakdown) {
            $summary['provenance_breakdown'] = $this->publicProvenanceBreakdown($calibers, $bulletTypes, $minStock);
        }

        return $summary;
    }

    /**
     * The shared standard-box aggregate: catalog / stock counts, in-stock
     * percentage, floor and average cost-per-round, and (when
     * `$withBestValue`) the cheapest in-stock offering. Every figure is
     * derived from whatever scope `$base` already carries.
     *
     * @return array<string, mixed>
     */
    private function standardBoxAggregate(Builder $base, bool $withBestValue): array
    {
        $total = (clone $base)->toBase()->count();
        $inStock = (clone $base)->toBase()->where('distributor_products.quantity_available', '>', 0)->count();
        $outOfStock = max(0, $total - $inStock);
        $percentage = $total > 0 ? round($inStock / $total * 100, 1) : 0.0;

        $rounds = $this->effectiveRoundsExpr();
        $cprExpr = "distributor_products.wholesale_price * 1.0 / {$rounds}";

        $priced = (clone $base)
            ->where('distributor_products.quantity_available', '>', 0)
            ->where('distributor_products.wholesale_price', '>', 0)
            ->whereRaw("{$rounds} > 0");

        $agg = (clone $priced)->toBase()
            ->selectRaw("MIN({$cprExpr}) as min_cpr")
            ->selectRaw("AVG({$cprExpr}) as avg_cpr")
            ->first();

        $result = [
            'total_catalog_offerings' => $total,
            'in_stock_count' => $inStock,
            'out_of_stock_count' => $outOfStock,
            'in_stock_percentage' => number_format($percentage, 1).'%',
            'lowest_cost_per_round' => $this->cprPair($agg->min_cpr ?? null),
            'average_cost_per_round' => $this->cprPair($agg->avg_cpr ?? null),
        ];

        if ($withBestValue) {
            $best = (clone $priced)
                ->select('distributor_products.*')
                ->selectRaw("{$rounds} as effective_round_count")
                ->selectRaw("{$cprExpr} as computed_cpr")
                ->with(['distributor', 'masterAmmunition'])
                ->orderByRaw("{$cprExpr} asc")
                ->first();

            $result['best_value_offering'] = $best ? $this->publicOfferingSummary($best, includeSpecs: true) : null;
        }

        return $result;
    }

    /**
     * The standard-box stats split three ways by provenance tier.
     *
     * Deliberately independent of any active `provenance_filter` — the
     * whole point is the full three-way view — but `min_stock_units`
     * still applies. Unclassified brands (no `brand_provenances` row)
     * match none of the three tiers and are silently absent from every
     * one, never folded into a tier.
     *
     * @param  array<int, string>  $calibers
     * @param  array<int, string>  $bulletTypes
     * @return array<string, array<string, mixed>>
     */
    private function publicProvenanceBreakdown(array $calibers, array $bulletTypes, int $minStock): array
    {
        $base = $this->publicApiOfferingQuery($calibers, $bulletTypes, $minStock, null);
        $this->applyPackagingFilter($base, 'standard');

        $out = [];

        foreach (BrandProvenance::TIERS as $tier) {
            $tierBase = (clone $base)->where('brand_provenances.provenance', $tier);
            $out[$tier] = $this->standardBoxAggregate($tierBase, withBestValue: false);
        }

        return $out;
    }

    /**
     * Bulk/case (>= 100 rd) stats for one caliber group, scoped to
     * currently in-stock lines only.
     *
     * @param  array<int, string>  $calibers
     * @param  array<int, string>  $bulletTypes
     * @return array<string, mixed>
     */
    private function publicBulkSummary(
        array $calibers,
        array $bulletTypes,
        int $minStock,
        ?string $provenanceFilter,
    ): array {
        $base = $this->publicApiOfferingQuery($calibers, $bulletTypes, $minStock, $provenanceFilter);
        $this->applyPackagingFilter($base, 'bulk');
        $base->where('distributor_products.quantity_available', '>', 0);

        $count = (clone $base)->toBase()->count();

        $rounds = $this->effectiveRoundsExpr();
        $cprExpr = "distributor_products.wholesale_price * 1.0 / {$rounds}";

        $priced = (clone $base)
            ->where('distributor_products.wholesale_price', '>', 0)
            ->whereRaw("{$rounds} > 0");

        $min = (clone $priced)->toBase()->selectRaw("MIN({$cprExpr}) as min_cpr")->value('min_cpr');

        $topDeal = (clone $priced)
            ->select('distributor_products.*')
            ->selectRaw("{$rounds} as effective_round_count")
            ->selectRaw("{$cprExpr} as computed_cpr")
            ->with(['distributor', 'masterAmmunition'])
            ->orderByRaw("{$cprExpr} asc")
            ->first();

        return [
            'available_bulk_skus_count' => $count,
            'lowest_bulk_cost_per_round' => $this->cprPair($min),
            'top_bulk_deal' => $topDeal ? $this->publicOfferingSummary($topDeal, includeSpecs: false) : null,
        ];
    }

    /**
     * Distinct brand names present in clean, in-stock ball-ammo offerings
     * across the five report calibers that have no `brand_provenances`
     * entry — a data-quality signal for "these still need classifying".
     *
     * Independent of `min_stock_units` / `provenance_filter`: it always
     * reports against live inventory so the list does not shrink just
     * because a caller filtered hard. The literal "Unknown" placeholder
     * and blank brands are not reported.
     *
     * @return array<int, string>
     */
    private function publicApiUnclassifiedBrands(): array
    {
        $calibers = collect(self::PUBLIC_API_CALIBER_GROUPS)
            ->pluck('calibers')
            ->flatten()
            ->unique()
            ->values()
            ->all();

        return $this->baseOfferingQuery($this->normalizeFilters(new Request))
            ->leftJoin('brand_provenances', function ($join) {
                $join->whereRaw('LOWER(brand_provenances.brand_name) = LOWER(master_ammunition.manufacturer)');
            })
            ->whereIn('master_ammunition.caliber', $calibers)
            ->whereIn('master_ammunition.bullet_type', self::PUBLIC_API_BALL_BULLET_TYPES)
            ->where('distributor_products.needs_review', false)
            ->where('distributor_products.quantity_available', '>', 0)
            ->whereNull('brand_provenances.id')
            ->whereNotNull('master_ammunition.manufacturer')
            ->whereRaw("TRIM(master_ammunition.manufacturer) <> ''")
            ->whereRaw('LOWER(master_ammunition.manufacturer) <> ?', ['unknown'])
            ->toBase()
            ->distinct()
            ->orderBy('master_ammunition.manufacturer')
            ->pluck('master_ammunition.manufacturer')
            ->all();
    }

    /**
     * The public-API shape for one offering. `includeSpecs` adds
     * `grain_weight` / `bullet_type` (the standard-box "best value"
     * shape); the bulk "top deal" shape omits them.
     *
     * @return array<string, mixed>
     */
    private function publicOfferingSummary(DistributorProduct $offering, bool $includeSpecs): array
    {
        $master = $offering->masterAmmunition;

        $summary = [
            'brand' => $master?->manufacturer,
        ];

        if ($includeSpecs) {
            $summary['grain_weight'] = $master?->bullet_weight_gr;
            $summary['bullet_type'] = $master?->bullet_type;
        }

        $summary['round_count'] = (int) $offering->getAttribute('effective_round_count');
        $summary['wholesale_price'] = $this->asFloat($offering->wholesale_price, 2);
        $summary['cost_per_round'] = $this->asFloat($offering->getAttribute('computed_cpr'), 4);
        $summary['distributor'] = $offering->distributor?->name;

        return $summary;
    }

    /**
     * @return array{formatted: ?string, raw: ?float}
     */
    private function cprPair($value): array
    {
        $raw = $this->asFloat($value, 4);

        return [
            'formatted' => $raw !== null ? '$'.number_format($raw, 4) : null,
            'raw' => $raw,
        ];
    }
}
