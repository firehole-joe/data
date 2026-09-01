<?php

namespace App\Services\Ammunition;

use App\Models\Distributor;
use App\Models\DistributorProduct;
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
        return DistributorProduct::query()
            ->join('master_ammunition', 'master_ammunition.id', '=', 'distributor_products.master_ammunition_id')
            ->where('master_ammunition.is_tracked_in_report', true)
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
    }

    /**
     * Aggregated, context-aware statistics for the stat-card row. Every
     * figure is computed strictly against the active filtered dataset.
     *
     * @return array<string, mixed>
     */
    public function stats(array $filters): array
    {
        $agg = $this->baseOfferingQuery($filters)->toBase()
            ->selectRaw('COUNT(*) as offer_count')
            ->selectRaw('COUNT(DISTINCT distributor_products.master_ammunition_id) as sku_count')
            ->selectRaw('COALESCE(SUM(distributor_products.quantity_available * master_ammunition.rounds_per_box), 0) as pipeline_rounds')
            ->selectRaw('MIN(CASE WHEN distributor_products.wholesale_price > 0 THEN distributor_products.wholesale_price END) as min_box')
            ->selectRaw('AVG(CASE WHEN distributor_products.wholesale_price > 0 THEN distributor_products.wholesale_price END) as avg_box')
            ->selectRaw('MAX(CASE WHEN distributor_products.wholesale_price > 0 THEN distributor_products.wholesale_price END) as max_box')
            ->selectRaw('MIN(CASE WHEN distributor_products.wholesale_price > 0 AND master_ammunition.rounds_per_box > 0 THEN distributor_products.wholesale_price * 1.0 / master_ammunition.rounds_per_box END) as min_cpr')
            ->selectRaw('AVG(CASE WHEN distributor_products.wholesale_price > 0 AND master_ammunition.rounds_per_box > 0 THEN distributor_products.wholesale_price * 1.0 / master_ammunition.rounds_per_box END) as avg_cpr')
            ->selectRaw('MAX(CASE WHEN distributor_products.wholesale_price > 0 AND master_ammunition.rounds_per_box > 0 THEN distributor_products.wholesale_price * 1.0 / master_ammunition.rounds_per_box END) as max_cpr')
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
        $dir = $filters['sort_dir'];

        $grouped = $this->baseOfferingQuery($filters)->toBase()
            ->select('distributor_products.master_ammunition_id as mid')
            ->selectRaw('MIN(CASE WHEN distributor_products.wholesale_price > 0 THEN distributor_products.wholesale_price END) as agg_best_price')
            ->selectRaw('MIN(CASE WHEN distributor_products.wholesale_price > 0 AND master_ammunition.rounds_per_box > 0 THEN distributor_products.wholesale_price * 1.0 / master_ammunition.rounds_per_box END) as agg_best_cpr')
            ->selectRaw('COALESCE(SUM(distributor_products.quantity_available), 0) as agg_total_qty')
            ->addSelect('master_ammunition.manufacturer as m_manufacturer')
            ->addSelect('master_ammunition.caliber as m_caliber')
            ->addSelect('master_ammunition.name as m_name')
            ->groupBy(
                'distributor_products.master_ammunition_id',
                'master_ammunition.manufacturer',
                'master_ammunition.caliber',
                'master_ammunition.name',
            );

        match ($filters['sort_by']) {
            'caliber' => $grouped->orderBy('m_caliber', $dir)->orderBy('m_manufacturer')->orderBy('m_name'),
            'name' => $grouped->orderBy('m_name', $dir),
            'best_price' => $grouped->orderByRaw('(agg_best_price IS NULL) asc')->orderBy('agg_best_price', $dir),
            'best_cpr' => $grouped->orderByRaw('(agg_best_cpr IS NULL) asc')->orderBy('agg_best_cpr', $dir),
            'total_qty' => $grouped->orderBy('agg_total_qty', $dir),
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

        $rows = collect($orderedIds)
            ->map(fn ($id) => $masters->get($id))
            ->filter()
            ->each(fn (MasterAmmunition $master) => $this->decorate($master))
            ->values();

        $paginator->setCollection($rows);

        return $paginator;
    }

    /**
     * The option lists the filter toolbar renders from.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        $tracked = MasterAmmunition::query()->where('is_tracked_in_report', true);

        return [
            'distributors' => Distributor::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'calibers' => (clone $tracked)->whereNotNull('caliber')->distinct()->orderBy('caliber')->pluck('caliber')->all(),
            'projectile_types' => (clone $tracked)->whereNotNull('bullet_type')->distinct()->orderBy('bullet_type')->pluck('bullet_type')->all(),
            'grain_weights' => (clone $tracked)->whereNotNull('bullet_weight_gr')->distinct()->orderBy('bullet_weight_gr')->pluck('bullet_weight_gr')->map(fn ($g) => (int) $g)->all(),
            'per_page_options' => self::PER_PAGE_OPTIONS,
            'featured_calibers' => self::FEATURED_CALIBERS,
            'featured_projectiles' => self::FEATURED_PROJECTILES,
        ];
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
        )->when($filters['stock_status'] === 'in_stock', fn ($sub) => $sub->where('is_in_stock', true))
            ->when($filters['stock_status'] === 'out_of_stock', fn ($sub) => $sub->where('is_in_stock', false))
            ->when($filters['min_qty'] > 0, fn ($sub) => $sub->where('quantity_available', '>=', $filters['min_qty']));
    }

    private function decorate(MasterAmmunition $master): void
    {
        $listings = $master->distributorProducts;
        $priced = $listings->filter(fn ($l) => (float) $l->wholesale_price > 0);

        $best = ($priced->isNotEmpty() ? $priced : $listings)->sortBy('wholesale_price')->first();
        $roundsPerBox = (int) $master->rounds_per_box;

        $master->best_price_per_box = $best ? (float) $best->wholesale_price : null;
        $master->best_price_per_round = ($best && $roundsPerBox > 0 && (float) $best->wholesale_price > 0)
            ? round((float) $best->wholesale_price / $roundsPerBox, 4)
            : null;
        $master->best_distributor_name = $best?->distributor?->name;
        $master->total_quantity_available = (int) $listings->sum('quantity_available');
        $master->in_stock = $listings->contains(fn ($l) => $l->is_in_stock && $l->quantity_available > 0);
        $master->listing_count = $listings->count();

        $master->distributor_badges = $listings
            ->map(fn ($l) => ['name' => $l->distributor?->name, 'in_stock' => (bool) $l->is_in_stock])
            ->filter(fn ($b) => $b['name'] !== null)
            ->unique('name')
            ->sortBy('name')
            ->values();

        $master->offerings = $listings->map(fn ($l) => [
            'distributor' => $l->distributor?->name,
            'sku' => $l->distributor_sku,
            'dealer_cost' => (float) $l->wholesale_price,
            'cpr' => ($roundsPerBox > 0 && (float) $l->wholesale_price > 0)
                ? round((float) $l->wholesale_price / $roundsPerBox, 4)
                : null,
            'qty' => (int) $l->quantity_available,
            'in_stock' => (bool) $l->is_in_stock,
            'updated_at' => $l->last_feed_update_at,
        ])->values();
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
