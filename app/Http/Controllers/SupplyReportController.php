<?php

namespace App\Http\Controllers;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\MasterRoundCountReconciler;
use App\Services\Ammunition\SupplyReportQueryService;
use App\Services\Feeds\DistributorSkuOverrideManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplyReportController extends Controller
{
    /** Calibers surfaced as quick-filter tabs, in display order. */
    public const FEATURED_CALIBERS = [
        '9mm Luger',
        '5.56x45mm NATO',
        '.223 Remington',
        '.300 AAC Blackout',
        '6.5 Creedmoor',
        '.308 Winchester',
        '.45 ACP',
        '.22 LR',
    ];

    private const PER_PAGE = 25;

    /** Session key holding the visitor's last-used dashboard filter set. */
    private const DASHBOARD_FILTER_SESSION_KEY = 'supply_dashboard_filters';

    /** Request keys captured into / restored from the dashboard filter session. */
    private const DASHBOARD_FILTER_KEYS = [
        'distributors',
        'distributor_mode',
        'calibers',
        'projectile_types',
        'grain_weights',
        'stock_status',
        'review',
        'packaging',
        'min_qty',
        'search',
        'per_page',
        'sort_by',
        'sort_dir',
    ];

    public function index(Request $request)
    {
        $caliber = trim((string) $request->input('caliber')) ?: 'All';
        $manufacturer = trim((string) $request->input('manufacturer')) ?: null;
        $search = trim((string) $request->input('search'));
        $inStockOnly = $request->boolean('in_stock_only', true);

        $listingConstraint = function ($query) use ($inStockOnly) {
            $query->when($inStockOnly, fn ($q) => $q->where('is_in_stock', true))
                ->orderBy('wholesale_price')
                ->with('distributor');
        };

        $masters = MasterAmmunition::query()
            ->where('is_tracked_in_report', true)
            ->with(['distributorProducts' => $listingConstraint])
            ->when($caliber !== 'All', fn ($q) => $q->where('caliber', $caliber))
            ->when($manufacturer, fn ($q) => $q->where('manufacturer', $manufacturer))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('mfr_part_number', 'like', $like)
                        ->orWhere('upc', 'like', $like);
                });
            })
            ->when($inStockOnly, fn ($q) => $q->whereHas(
                'distributorProducts',
                fn ($sub) => $sub->where('is_in_stock', true)
            ))
            ->orderBy('manufacturer')
            ->orderBy('caliber')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $masters->getCollection()->each(function (MasterAmmunition $master) {
            $listings = $master->distributorProducts;

            $best = $listings
                ->filter(fn ($listing) => (float) $listing->wholesale_price > 0)
                ->sortBy('wholesale_price')
                ->first()
                ?? $listings->sortBy('wholesale_price')->first();

            $master->best_price_per_box = $best ? (float) $best->wholesale_price : null;
            $master->best_price_per_round = ($best && $master->rounds_per_box > 0)
                ? round((float) $best->wholesale_price / $master->rounds_per_box, 4)
                : null;
            $master->best_distributor_name = $best?->distributor?->name;
            $master->total_quantity_available = (int) $listings->sum('quantity_available');
            $master->listing_count = $listings->count();
        });

        return view('supply.index', [
            'masters' => $masters,
            'kpis' => $this->kpis(),
            'tabs' => $this->caliberTabs(),
            'manufacturers' => $this->manufacturerOptions(),
            'distributorCount' => Distributor::count(),
            'filters' => [
                'caliber' => $caliber,
                'manufacturer' => $manufacturer,
                'search' => $search,
                'in_stock_only' => $inStockOnly,
            ],
        ]);
    }

    /**
     * The context-aware Ammunition Supply Dashboard: multi-distributor
     * accordion table, filtered stat cards and advanced attribute
     * filters, all driven by {@see SupplyReportQueryService}.
     *
     * Filter selections survive navigation away and back: a request that
     * carries filter params updates the `supply_dashboard_filters`
     * session bag; a bare request (nav link, or returning from the Live
     * Supply Report) is redirected back onto the stored query string so
     * the URL, pills and accordions stay in sync. `?reset=1` clears the
     * bag and bypasses restoration entirely.
     */
    public function dashboard(Request $request, SupplyReportQueryService $query)
    {
        if ($request->has('reset')) {
            $request->session()->forget(self::DASHBOARD_FILTER_SESSION_KEY);

            return redirect()->route('supply.dashboard');
        }

        $incoming = array_filter(
            $request->only(self::DASHBOARD_FILTER_KEYS),
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );

        if ($incoming !== []) {
            // The URL carries filters — mirror them into the session.
            $request->session()->put(self::DASHBOARD_FILTER_SESSION_KEY, $incoming);
        } else {
            // Bare visit — re-hydrate the last-used filters from the session.
            $stored = array_filter(
                (array) $request->session()->get(self::DASHBOARD_FILTER_SESSION_KEY, []),
                fn ($value) => $value !== null && $value !== '' && $value !== [],
            );

            if ($stored !== []) {
                return redirect()->route('supply.dashboard', $stored);
            }

            $request->session()->forget(self::DASHBOARD_FILTER_SESSION_KEY);
        }

        $filters = $query->normalizeFilters($request);

        // Total offerings still awaiting review, catalog-wide (independent
        // of the active filter scope). The Review status filter is only
        // worth showing when there is something to review — or when the
        // visitor is already filtering on `review`.
        $flaggedCount = $this->flaggedOfferingCount();

        return view('supply.dashboard', [
            'masters' => $query->paginate($filters),
            'stats' => $query->stats($filters),
            'facets' => $query->facets($filters),
            'filters' => $filters,
            'perPageOptions' => SupplyReportQueryService::PER_PAGE_OPTIONS,
            'flaggedCount' => $flaggedCount,
            'showReviewFilter' => $flaggedCount > 0 || $request->has('review'),
            'canResolve' => $request->user()?->isAdmin() === true
                || $request->session()->get('feed_admin_authenticated') === true,
        ]);
    }

    /**
     * Catalog-wide count of offerings flagged for review (tracked master,
     * not reviewer-dismissed).
     */
    private function flaggedOfferingCount(): int
    {
        return DistributorProduct::query()
            ->where('distributor_products.needs_review', true)
            ->where('distributor_products.is_ignored', false)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('master_ammunition')
                ->whereColumn('master_ammunition.id', 'distributor_products.master_ammunition_id')
                ->where('master_ammunition.is_tracked_in_report', true))
            ->count();
    }

    /**
     * Approve a flagged offering: pin the reviewer-confirmed rounds-per-
     * unit count to the row, recompute its cost-per-round, clear the
     * review flag, and record the count in `distributor_sku_overrides`
     * so every future feed import re-applies it without re-flagging.
     *
     * The parent product's Best $/Box and Best $/Rd rollups and the
     * FLAGGED count pill are derived live by {@see SupplyReportQueryService}
     * on the next render, so a redirect back to the dashboard is enough
     * to reflect the change.
     */
    public function approveOffering(
        Request $request,
        DistributorProduct $offering,
        DistributorSkuOverrideManager $overrides,
        MasterRoundCountReconciler $reconciler,
    ) {
        $validated = $request->validate([
            'round_count' => ['required', 'integer', 'min:1', 'max:5000'],
        ]);

        $roundCount = (int) $validated['round_count'];
        $price = (float) $offering->wholesale_price;

        $offering->forceFill([
            'round_count' => $roundCount,
            'cost_per_round' => $price > 0 ? round($price / $roundCount, 4) : null,
            'needs_review' => false,
            'review_reason' => null,
            'is_ignored' => false,
        ])->save();

        // Durable ledger entry keyed by distributor SKU + UPC, with a
        // price / description snapshot so a later import only resurfaces
        // this listing if the data drifts materially.
        $overrides->recordApproved($offering, $roundCount);

        // If a case SKU had pinned the shared master to a case count while
        // this offering is a standard box, pull the master back to the
        // box count so its sibling listings stop dividing by a case.
        $offering->loadMissing('masterAmmunition');
        $reconciler->reconcile($offering->masterAmmunition);

        return redirect()
            ->back(fallback: route('supply.dashboard'))
            ->with('success', "Offering approved at {$roundCount} rounds/unit — future imports will use this count.");
    }

    /**
     * Permanently dismiss a flagged offering: it is hidden from the
     * flagged view, held out of every market calculation, and recorded in
     * the durable override ledger so it stays dismissed across future
     * feed imports (matched by UPC or distributor SKU).
     */
    public function ignoreOffering(
        Request $request,
        DistributorProduct $offering,
        DistributorSkuOverrideManager $overrides,
    ) {
        $offering->forceFill([
            'is_ignored' => true,
            'needs_review' => false,
            'review_reason' => null,
        ])->save();

        $overrides->recordIgnored($offering);

        return redirect()
            ->back(fallback: route('supply.dashboard'))
            ->with('success', 'Offering ignored — it will stay out of the flagged view and market calculations.');
    }

    /**
     * Manually push an otherwise-clean offering into the review queue
     * from the dashboard drawer (`needs_review = true`). The optional
     * `review_reason` records why; a blank one falls back to a generic
     * "flagged by an administrator" note.
     */
    public function flagOffering(Request $request, DistributorProduct $offering)
    {
        $reason = trim((string) $request->input('review_reason'));

        $offering->forceFill([
            'needs_review' => true,
            'review_reason' => $reason !== '' ? $reason : 'Manually flagged for review by an administrator.',
            'is_ignored' => false,
        ])->save();

        return redirect()
            ->back(fallback: route('supply.dashboard'))
            ->with('success', 'Offering flagged — it is now in the review queue.');
    }

    /**
     * Bulk-dismiss every offering still flagged for review within the
     * active dashboard filter selection: each is marked `is_ignored`,
     * cleared of its review flag, and written to the durable override
     * ledger so it stays dismissed across future feed imports.
     */
    public function ignoreAllOfferings(
        Request $request,
        SupplyReportQueryService $query,
        DistributorSkuOverrideManager $overrides,
    ) {
        $filters = $query->normalizeFilters($request);

        $flagged = $query->baseOfferingQuery($filters)
            ->where('distributor_products.needs_review', true)
            ->select('distributor_products.*')
            ->with('masterAmmunition')
            ->get();

        $count = 0;

        DB::transaction(function () use ($flagged, $overrides, &$count) {
            foreach ($flagged as $offering) {
                $offering->forceFill([
                    'is_ignored' => true,
                    'needs_review' => false,
                    'review_reason' => null,
                ])->save();

                $overrides->recordIgnored($offering);
                $count++;
            }
        });

        return redirect()
            ->back(fallback: route('supply.dashboard', ['review' => 'flagged']))
            ->with('success', "Successfully ignored {$count} reviewable item".($count === 1 ? '' : 's').'.');
    }

    public function distributors()
    {
        $distributors = Distributor::query()
            ->withCount([
                'distributorProducts',
                'distributorProducts as needs_review_count' => fn ($q) => $q->where('needs_review', true),
            ])
            ->with('latestFeedRun')
            ->orderBy('name')
            ->get()
            ->map(function (Distributor $distributor) {
                $run = $distributor->latestFeedRun;

                return [
                    'name' => $distributor->name,
                    'slug' => $distributor->slug,
                    'transport_type' => $distributor->transport_type,
                    'is_active' => (bool) $distributor->is_active,
                    'last_synced_at' => $distributor->last_synced_at,
                    'latest_status' => $run?->status,
                    'latest_run_at' => $run?->finished_at ?? $run?->started_at ?? $run?->created_at,
                    'products_tracked' => (int) $distributor->distributor_products_count,
                    'needs_review_count' => (int) $distributor->needs_review_count,
                ];
            });

        return view('supply.distributors', [
            'distributors' => $distributors,
        ]);
    }

    /**
     * Headline KPIs for the report banner.
     *
     * @return array<int, array<string, string>>
     */
    private function kpis(): array
    {
        $trackedMasters = MasterAmmunition::where('is_tracked_in_report', true)->count();
        $inStockListings = DistributorProduct::where('is_in_stock', true)->count();

        $lowestPerRound = fn (string $caliber): ?float => $this->lowestPricePerRound($caliber);

        $lowest9mm = $lowestPerRound('9mm Luger');
        $lowest556 = $lowestPerRound('5.56x45mm NATO');

        $totalRounds = (int) DistributorProduct::query()
            ->where('distributor_products.is_in_stock', true)
            ->join('master_ammunition', 'master_ammunition.id', '=', 'distributor_products.master_ammunition_id')
            ->sum(DB::raw('distributor_products.quantity_available * master_ammunition.rounds_per_box'));

        return [
            ['title' => 'Tracked Master SKUs', 'value' => number_format($trackedMasters)],
            ['title' => 'In-Stock Listings', 'value' => number_format($inStockListings)],
            ['title' => 'Lowest 9mm $/rd', 'value' => $lowest9mm !== null ? '$'.number_format($lowest9mm, 3) : '—'],
            ['title' => 'Lowest 5.56 $/rd', 'value' => $lowest556 !== null ? '$'.number_format($lowest556, 3) : '—'],
            ['title' => 'Rounds in Supply Chain', 'value' => number_format($totalRounds)],
        ];
    }

    private function lowestPricePerRound(string $caliber): ?float
    {
        // Effective rounds-per-unit: the offering's own confirmed count,
        // then its SKU override, and only then the master's box count —
        // so a case SKU that poisoned the master to 1000 can never drag
        // this figure to a fraction of a cent.
        $rounds = 'COALESCE(distributor_products.round_count, '
            .'NULLIF(distributor_sku_overrides.round_count, 0), master_ammunition.rounds_per_box)';

        $value = DistributorProduct::query()
            ->where('distributor_products.is_in_stock', true)
            ->where('distributor_products.wholesale_price', '>', 0)
            // A flagged / dismissed offering carries a distrusted price
            // and must never be the "lowest".
            ->where('distributor_products.needs_review', false)
            ->where('distributor_products.is_ignored', false)
            ->join('master_ammunition', 'master_ammunition.id', '=', 'distributor_products.master_ammunition_id')
            ->leftJoin('distributor_sku_overrides', function ($join) {
                $join->on('distributor_sku_overrides.distributor_id', '=', 'distributor_products.distributor_id')
                    ->on('distributor_sku_overrides.distributor_sku', '=', 'distributor_products.distributor_sku');
            })
            ->where('master_ammunition.caliber', $caliber)
            ->where('master_ammunition.is_tracked_in_report', true)
            ->whereRaw("{$rounds} > 0")
            // `* 1.0` forces float division — SQLite would otherwise do
            // integer division when both operands land on whole numbers.
            ->min(DB::raw("distributor_products.wholesale_price * 1.0 / {$rounds}"));

        return $value !== null ? round((float) $value, 4) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function caliberTabs(): array
    {
        $counts = MasterAmmunition::query()
            ->where('is_tracked_in_report', true)
            ->select('caliber', DB::raw('count(*) as aggregate'))
            ->groupBy('caliber')
            ->pluck('aggregate', 'caliber');

        $tabs = [
            ['label' => 'All', 'value' => 'All', 'count' => (int) $counts->sum()],
        ];

        foreach (self::FEATURED_CALIBERS as $caliber) {
            $tabs[] = [
                'label' => $caliber,
                'value' => $caliber,
                'count' => (int) ($counts[$caliber] ?? 0),
            ];
        }

        return $tabs;
    }

    /**
     * @return array<int, string>
     */
    private function manufacturerOptions(): array
    {
        return MasterAmmunition::query()
            ->where('is_tracked_in_report', true)
            ->whereNotNull('manufacturer')
            ->distinct()
            ->orderBy('manufacturer')
            ->pluck('manufacturer')
            ->all();
    }
}
