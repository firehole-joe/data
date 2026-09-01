<?php

namespace App\Http\Controllers;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\SupplyReportQueryService;
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

        return view('supply.dashboard', [
            'masters' => $query->paginate($filters),
            'stats' => $query->stats($filters),
            'facets' => $query->facets($filters),
            'filters' => $filters,
            'perPageOptions' => SupplyReportQueryService::PER_PAGE_OPTIONS,
        ]);
    }

    public function distributors()
    {
        $distributors = Distributor::query()
            ->withCount('distributorProducts')
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
        $value = DistributorProduct::query()
            ->where('distributor_products.is_in_stock', true)
            ->where('distributor_products.wholesale_price', '>', 0)
            ->join('master_ammunition', 'master_ammunition.id', '=', 'distributor_products.master_ammunition_id')
            ->where('master_ammunition.caliber', $caliber)
            ->where('master_ammunition.is_tracked_in_report', true)
            ->where('master_ammunition.rounds_per_box', '>', 0)
            // `* 1.0` forces float division — SQLite would otherwise do
            // integer division when both operands land on whole numbers.
            ->min(DB::raw('distributor_products.wholesale_price * 1.0 / master_ammunition.rounds_per_box'));

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
