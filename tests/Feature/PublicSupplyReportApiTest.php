<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PublicSupplyReportController;
use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use Database\Seeders\BrandProvenanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicSupplyReportApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/supply-summary';

    protected function setUp(): void
    {
        parent::setUp();

        // Each summary response is cached under a per-query key; flush the
        // store before every test so one test's seeded data can never
        // leak into the next via a stale cached payload.
        Cache::flush();
    }

    private function apiKey(): string
    {
        return (string) config('services.reports.api_key');
    }

    private function seedProvenance(): void
    {
        $this->seed(BrandProvenanceSeeder::class);
    }

    private function callApi(array $query = [], array $headers = [])
    {
        $url = self::ENDPOINT.($query ? '?'.http_build_query($query) : '');

        return $this->withHeaders($headers)->getJson($url);
    }

    private function distributor(array $overrides = []): Distributor
    {
        static $n = 0;
        $n++;

        return Distributor::create(array_merge([
            'name' => "Distributor {$n}",
            'slug' => "dist-{$n}",
            'driver_class' => 'App\\Services\\Feeds\\Drivers\\RsrFeedDriver',
            'transport_type' => 'sftp',
            'connection_settings' => [],
            'is_active' => true,
        ], $overrides));
    }

    private function master(array $overrides = []): MasterAmmunition
    {
        static $n = 0;
        $n++;

        return MasterAmmunition::create(array_merge([
            'manufacturer' => 'Federal',
            'mfr_part_number' => "MPN-{$n}",
            'name' => "Master {$n}",
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 115,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 50,
            'is_tracked_in_report' => true,
        ], $overrides));
    }

    private function listing(MasterAmmunition $master, Distributor $distributor, array $overrides = []): DistributorProduct
    {
        static $n = 0;
        $n++;

        return DistributorProduct::create(array_merge([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => "SKU-{$n}",
            'raw_description' => 'raw description',
            'wholesale_price' => 10.00,
            'quantity_available' => 100,
            'is_in_stock' => true,
        ], $overrides));
    }

    /* ------------------------------------------------------------------ */
    /*  Authentication */
    /* ------------------------------------------------------------------ */

    public function test_it_returns_401_with_no_api_key(): void
    {
        $this->callApi()->assertStatus(401)->assertJsonStructure(['message']);
    }

    public function test_it_returns_401_with_a_wrong_query_param_key(): void
    {
        $this->callApi(['api_key' => 'not-the-right-key'])->assertStatus(401);
    }

    public function test_it_returns_401_with_a_wrong_bearer_token(): void
    {
        $this->callApi([], ['Authorization' => 'Bearer not-the-right-key'])->assertStatus(401);
    }

    public function test_a_valid_query_param_key_is_accepted(): void
    {
        $this->callApi(['api_key' => $this->apiKey()])->assertOk();
    }

    public function test_a_valid_bearer_token_is_accepted(): void
    {
        $this->callApi([], ['Authorization' => 'Bearer '.$this->apiKey()])->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  Schema */
    /* ------------------------------------------------------------------ */

    public function test_it_returns_the_complete_json_schema(): void
    {
        $this->callApi(['api_key' => $this->apiKey()])
            ->assertOk()
            ->assertJsonStructure([
                'meta' => [
                    'generated_at',
                    'generated_at_human',
                    'distributors_active',
                    'filters' => ['min_stock_units', 'provenance_filter', 'include_provenance_breakdown'],
                ],
                'calibers' => [
                    '9mm Luger' => [
                        'total_catalog_offerings',
                        'in_stock_count',
                        'out_of_stock_count',
                        'in_stock_percentage',
                        'lowest_cost_per_round' => ['formatted', 'raw'],
                        'average_cost_per_round' => ['formatted', 'raw'],
                        'best_value_offering',
                    ],
                    '5.56 NATO',
                    '.300 AAC Blackout',
                    '.45 ACP',
                    '.357 Magnum',
                ],
                'bulk_offerings' => [
                    '9mm Luger' => [
                        'available_bulk_skus_count',
                        'lowest_bulk_cost_per_round' => ['formatted', 'raw'],
                        'top_bulk_deal',
                    ],
                    '5.56 NATO',
                    '.300 AAC Blackout',
                    '.45 ACP',
                    '.357 Magnum',
                ],
                'unclassified_brands',
            ]);
    }

    public function test_distributors_active_reflects_seeded_data(): void
    {
        $this->distributor(['is_active' => true]);
        $this->distributor(['is_active' => true]);
        $this->distributor(['is_active' => false]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();

        $this->assertSame(2, $data['meta']['distributors_active']);
    }

    /* ------------------------------------------------------------------ */
    /*  Standard packaging (<= 50 rd), stock counts & percentage */
    /* ------------------------------------------------------------------ */

    public function test_standard_box_stats_and_in_stock_percentage_are_computed_correctly(): void
    {
        $d = $this->distributor(['name' => 'RSR Group']);

        // Cheapest, in stock: $6.50 / 50 = $0.13/rd.
        $cheap = $this->master(['mfr_part_number' => 'STD-1']);
        $this->listing($cheap, $d, ['wholesale_price' => 6.50, 'quantity_available' => 50, 'is_in_stock' => true]);

        // Pricier, in stock: $10.00 / 50 = $0.20/rd.
        $pricier = $this->master(['mfr_part_number' => 'STD-2']);
        $this->listing($pricier, $d, ['wholesale_price' => 10.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        // Out of stock — counted in the catalog total but not in pricing.
        $oos = $this->master(['mfr_part_number' => 'STD-3']);
        $this->listing($oos, $d, ['wholesale_price' => 8.00, 'quantity_available' => 0, 'is_in_stock' => false]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $nine = $data['calibers']['9mm Luger'];

        $this->assertSame(3, $nine['total_catalog_offerings']);
        $this->assertSame(2, $nine['in_stock_count']);
        $this->assertSame(1, $nine['out_of_stock_count']);
        // 2 / 3 = 66.7%.
        $this->assertSame('66.7%', $nine['in_stock_percentage']);

        $this->assertEqualsWithDelta(0.13, $nine['lowest_cost_per_round']['raw'], 0.001);
        $this->assertSame('$0.1300', $nine['lowest_cost_per_round']['formatted']);
        // Average of the two IN-STOCK offerings only: (0.13 + 0.20) / 2.
        $this->assertEqualsWithDelta(0.165, $nine['average_cost_per_round']['raw'], 0.001);

        $best = $nine['best_value_offering'];
        $this->assertSame('Federal', $best['brand']);
        $this->assertSame(115, $best['grain_weight']);
        $this->assertSame('FMJ', $best['bullet_type']);
        $this->assertSame(50, $best['round_count']);
        $this->assertEqualsWithDelta(6.50, $best['wholesale_price'], 0.001);
        $this->assertEqualsWithDelta(0.13, $best['cost_per_round'], 0.001);
        $this->assertSame('RSR Group', $best['distributor']);
    }

    public function test_a_master_with_no_offerings_reports_zeroed_stats(): void
    {
        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $blackout = $data['calibers']['.300 AAC Blackout'];

        $this->assertSame(0, $blackout['total_catalog_offerings']);
        $this->assertSame(0, $blackout['in_stock_count']);
        $this->assertSame(0, $blackout['out_of_stock_count']);
        $this->assertSame('0.0%', $blackout['in_stock_percentage']);
        $this->assertNull($blackout['lowest_cost_per_round']['raw']);
        $this->assertNull($blackout['best_value_offering']);
    }

    /* ------------------------------------------------------------------ */
    /*  FMJ-only filtering */
    /* ------------------------------------------------------------------ */

    public function test_non_fmj_offerings_are_excluded_from_a_standard_fmj_only_caliber(): void
    {
        $d = $this->distributor();

        $fmj = $this->master(['mfr_part_number' => 'FMJ-1', 'bullet_type' => 'FMJ']);
        $this->listing($fmj, $d, ['wholesale_price' => 9.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        // Artificially far cheaper, but not FMJ — must never win "lowest".
        $jhp = $this->master(['mfr_part_number' => 'JHP-1', 'bullet_type' => 'JHP']);
        $this->listing($jhp, $d, ['wholesale_price' => 1.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $nine = $data['calibers']['9mm Luger'];

        $this->assertSame(1, $nine['total_catalog_offerings'], 'the JHP line must not be counted');
        $this->assertEqualsWithDelta(0.18, $nine['lowest_cost_per_round']['raw'], 0.001);
    }

    public function test_357_magnum_tracks_ball_ammo_only_excluding_sp_and_hollow_point(): void
    {
        $d = $this->distributor();

        // Ball / target loads — counted.
        $fmj = $this->master(['mfr_part_number' => 'MAG-FMJ', 'caliber' => '.357 Magnum', 'bullet_type' => 'FMJ']);
        $this->listing($fmj, $d, ['wholesale_price' => 12.00, 'quantity_available' => 20, 'is_in_stock' => true]);

        $tmj = $this->master(['mfr_part_number' => 'MAG-TMJ', 'caliber' => '.357 Magnum', 'bullet_type' => 'TMJ']);
        $this->listing($tmj, $d, ['wholesale_price' => 13.00, 'quantity_available' => 20, 'is_in_stock' => true]);

        // Soft point — now excluded even though it is cheaper.
        $sp = $this->master(['mfr_part_number' => 'MAG-SP', 'caliber' => '.357 Magnum', 'bullet_type' => 'SP']);
        $this->listing($sp, $d, ['wholesale_price' => 11.00, 'quantity_available' => 20, 'is_in_stock' => true]);

        // Hollow point — excluded, and the cheapest of all.
        $hp = $this->master(['mfr_part_number' => 'MAG-HP', 'caliber' => '.357 Magnum', 'bullet_type' => 'HP']);
        $this->listing($hp, $d, ['wholesale_price' => 5.00, 'quantity_available' => 20, 'is_in_stock' => true]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $magnum = $data['calibers']['.357 Magnum'];

        $this->assertSame(2, $magnum['total_catalog_offerings'], 'only the FMJ and TMJ ball lines count');
        // Cheapest ball load: $12.00 / 50 = $0.24/rd — not the $0.22 SP or $0.10 HP.
        $this->assertEqualsWithDelta(0.24, $magnum['lowest_cost_per_round']['raw'], 0.001);
        $this->assertSame('FMJ', $magnum['best_value_offering']['bullet_type']);
    }

    public function test_556_and_223_fmj_loads_are_grouped_under_556_nato(): void
    {
        $d = $this->distributor();

        $five56 = $this->master([
            'mfr_part_number' => 'M855', 'caliber' => '5.56x45mm NATO', 'bullet_type' => 'FMJ', 'rounds_per_box' => 20,
        ]);
        $this->listing($five56, $d, ['wholesale_price' => 7.00, 'quantity_available' => 40, 'is_in_stock' => true]);

        $two23 = $this->master([
            'mfr_part_number' => 'XM193', 'caliber' => '.223 Remington', 'bullet_type' => 'FMJ', 'rounds_per_box' => 20,
        ]);
        $this->listing($two23, $d, ['wholesale_price' => 6.50, 'quantity_available' => 40, 'is_in_stock' => true]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $group = $data['calibers']['5.56 NATO'];

        $this->assertSame(2, $group['total_catalog_offerings'], '5.56 NATO and .223 Remington share one bucket');
        $this->assertEqualsWithDelta(0.325, $group['lowest_cost_per_round']['raw'], 0.001);
    }

    /* ------------------------------------------------------------------ */
    /*  Bulk / case packaging (>= 100 rd) */
    /* ------------------------------------------------------------------ */

    public function test_bulk_offerings_are_kept_separate_from_standard_boxes(): void
    {
        $d = $this->distributor(['name' => 'Davidson\'s']);
        $master = $this->master(['mfr_part_number' => 'BULK-1']);

        // A standard 50-round box under the same master/caliber…
        $this->listing($master, $d, [
            'distributor_sku' => 'BOX-1',
            'wholesale_price' => 10.00,
            'quantity_available' => 100,
            'is_in_stock' => true,
        ]);

        // …and a 1000-round case, priced and counted for THIS offering
        // independent of the shared master's 50-round box count.
        $this->listing($master, $d, [
            'distributor_sku' => 'CASE-1',
            'wholesale_price' => 180.00,
            'round_count' => 1000,
            'quantity_available' => 10,
            'is_in_stock' => true,
        ]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $standard = $data['calibers']['9mm Luger'];
        $bulk = $data['bulk_offerings']['9mm Luger'];

        // The case never counts toward the standard-box catalog…
        $this->assertSame(1, $standard['total_catalog_offerings']);
        $this->assertEqualsWithDelta(0.20, $standard['lowest_cost_per_round']['raw'], 0.001);

        // …and the box never counts toward the bulk tally.
        $this->assertSame(1, $bulk['available_bulk_skus_count']);
        $this->assertEqualsWithDelta(0.18, $bulk['lowest_bulk_cost_per_round']['raw'], 0.001);

        $deal = $bulk['top_bulk_deal'];
        $this->assertArrayNotHasKey('grain_weight', $deal, 'the bulk deal shape omits specs');
        $this->assertSame(1000, $deal['round_count']);
        $this->assertEqualsWithDelta(180.00, $deal['wholesale_price'], 0.001);
        $this->assertEqualsWithDelta(0.18, $deal['cost_per_round'], 0.001);
        $this->assertSame("Davidson's", $deal['distributor']);
    }

    public function test_an_out_of_stock_bulk_offering_does_not_count_as_available(): void
    {
        $d = $this->distributor();
        $master = $this->master(['mfr_part_number' => 'BULK-OOS']);

        $this->listing($master, $d, [
            'wholesale_price' => 180.00,
            'round_count' => 1000,
            'quantity_available' => 0,
            'is_in_stock' => false,
        ]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $bulk = $data['bulk_offerings']['9mm Luger'];

        $this->assertSame(0, $bulk['available_bulk_skus_count']);
        $this->assertNull($bulk['lowest_bulk_cost_per_round']['raw']);
        $this->assertNull($bulk['top_bulk_deal']);
    }

    /* ------------------------------------------------------------------ */
    /*  Data quality */
    /* ------------------------------------------------------------------ */

    public function test_flagged_offerings_never_surface_in_the_public_feed(): void
    {
        $d = $this->distributor();
        $master = $this->master(['mfr_part_number' => 'FLAGGED-1']);

        $this->listing($master, $d, [
            'wholesale_price' => 0.05,
            'quantity_available' => 100,
            'is_in_stock' => true,
            'needs_review' => true,
            'review_reason' => 'below the centerfire floor',
        ]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $nine = $data['calibers']['9mm Luger'];

        $this->assertSame(0, $nine['total_catalog_offerings']);
        $this->assertNull($nine['lowest_cost_per_round']['raw']);
    }

    public function test_the_response_is_cached_for_the_configured_ttl(): void
    {
        $d = $this->distributor();
        $master = $this->master();
        $this->listing($master, $d, ['wholesale_price' => 6.50, 'quantity_available' => 50, 'is_in_stock' => true]);

        $first = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();

        // A new, cheaper offering appears after the first request…
        $this->listing($master, $d, ['wholesale_price' => 1.00, 'quantity_available' => 50, 'is_in_stock' => true]);

        $second = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();

        // …but the cached payload is served unchanged within the TTL.
        $this->assertSame($first, $second);

        $expectedKey = PublicSupplyReportController::CACHE_KEY.'_'
            .md5((string) json_encode(['api_key' => $this->apiKey()]));
        $this->assertTrue(Cache::has($expectedKey));
    }

    /* ------------------------------------------------------------------ */
    /*  Brand provenance endpoint */
    /* ------------------------------------------------------------------ */

    public function test_brand_provenance_endpoint_requires_the_api_key(): void
    {
        $this->getJson('/api/v1/brand-provenance')->assertStatus(401);
        $this->getJson('/api/v1/brand-provenance?api_key=wrong')->assertStatus(401);
    }

    public function test_brand_provenance_endpoint_returns_the_seeded_mapping(): void
    {
        $this->seedProvenance();

        $data = $this->getJson('/api/v1/brand-provenance?api_key='.$this->apiKey())
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['generated_at', 'tiers', 'count'],
                'brands' => [['brand', 'provenance', 'notes']],
            ])
            ->json();

        $this->assertSame(
            [
                'american_owned_american_made',
                'foreign_owned_us_manufactured',
                'imported_or_repackaged',
            ],
            $data['meta']['tiers'],
        );
        $this->assertSame(count($data['brands']), $data['meta']['count']);

        $byBrand = collect($data['brands'])->keyBy('brand');
        $this->assertSame('american_owned_american_made', $byBrand['Winchester']['provenance']);
        $this->assertSame('foreign_owned_us_manufactured', $byBrand['Federal']['provenance']);
        $this->assertSame('imported_or_repackaged', $byBrand['Magtech']['provenance']);
    }

    /* ------------------------------------------------------------------ */
    /*  min_stock_units */
    /* ------------------------------------------------------------------ */

    public function test_min_stock_units_recalculates_every_figure_on_the_filtered_set(): void
    {
        $d = $this->distributor();

        // Deep stock, cheapest: $6.00 / 50 = $0.12/rd.
        $deep = $this->master(['mfr_part_number' => 'MSU-DEEP']);
        $this->listing($deep, $d, ['wholesale_price' => 6.00, 'quantity_available' => 300, 'is_in_stock' => true]);

        // Thin stock: excluded once the floor is 200.
        $thin = $this->master(['mfr_part_number' => 'MSU-THIN']);
        $this->listing($thin, $d, ['wholesale_price' => 8.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        // Out of stock: always excluded from availability, and by the floor.
        $oos = $this->master(['mfr_part_number' => 'MSU-OOS']);
        $this->listing($oos, $d, ['wholesale_price' => 5.00, 'quantity_available' => 0, 'is_in_stock' => false]);

        $unfiltered = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $this->assertSame(3, $unfiltered['calibers']['9mm Luger']['total_catalog_offerings']);
        $this->assertSame(2, $unfiltered['calibers']['9mm Luger']['in_stock_count']);

        $filtered = $this->callApi([
            'api_key' => $this->apiKey(),
            'min_stock_units' => 200,
        ])->assertOk()->json();
        $nine = $filtered['calibers']['9mm Luger'];

        $this->assertSame(1, $nine['total_catalog_offerings'], 'only the 300-unit line clears the floor');
        $this->assertSame(1, $nine['in_stock_count']);
        $this->assertSame(0, $nine['out_of_stock_count']);
        $this->assertSame('100.0%', $nine['in_stock_percentage']);
        $this->assertEqualsWithDelta(0.12, $nine['lowest_cost_per_round']['raw'], 0.001);
        $this->assertEqualsWithDelta(0.12, $nine['average_cost_per_round']['raw'], 0.001);
        $this->assertSame(200, $filtered['meta']['filters']['min_stock_units']);
    }

    /* ------------------------------------------------------------------ */
    /*  provenance_filter */
    /* ------------------------------------------------------------------ */

    public function test_provenance_filter_restricts_to_one_tier_and_drops_unclassified(): void
    {
        $this->seedProvenance();
        $d = $this->distributor();

        $winchester = $this->master(['manufacturer' => 'Winchester', 'mfr_part_number' => 'PF-WIN']);
        $this->listing($winchester, $d, ['wholesale_price' => 9.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        $federal = $this->master(['manufacturer' => 'Federal', 'mfr_part_number' => 'PF-FED']);
        $this->listing($federal, $d, ['wholesale_price' => 7.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        // Unclassified — cheapest, so it would win any blended "lowest".
        $blazer = $this->master(['manufacturer' => 'Blazer', 'mfr_part_number' => 'PF-BLZ']);
        $this->listing($blazer, $d, ['wholesale_price' => 2.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        $blended = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $this->assertSame(3, $blended['calibers']['9mm Luger']['total_catalog_offerings']);
        $this->assertEqualsWithDelta(0.04, $blended['calibers']['9mm Luger']['lowest_cost_per_round']['raw'], 0.001);

        $american = $this->callApi([
            'api_key' => $this->apiKey(),
            'provenance_filter' => 'american_owned_american_made',
        ])->assertOk()->json();
        $nine = $american['calibers']['9mm Luger'];

        $this->assertSame(1, $nine['total_catalog_offerings'], 'only Winchester is American-made');
        $this->assertEqualsWithDelta(0.18, $nine['lowest_cost_per_round']['raw'], 0.001);
        $this->assertSame('Winchester', $nine['best_value_offering']['brand']);
        $this->assertSame('american_owned_american_made', $american['meta']['filters']['provenance_filter']);
    }

    public function test_an_unknown_provenance_filter_value_is_ignored(): void
    {
        $this->seedProvenance();
        $d = $this->distributor();
        $federal = $this->master(['manufacturer' => 'Federal']);
        $this->listing($federal, $d, ['quantity_available' => 100, 'is_in_stock' => true]);

        $data = $this->callApi([
            'api_key' => $this->apiKey(),
            'provenance_filter' => 'not-a-real-tier',
        ])->assertOk()->json();

        $this->assertNull($data['meta']['filters']['provenance_filter']);
        $this->assertSame(1, $data['calibers']['9mm Luger']['total_catalog_offerings']);
    }

    /* ------------------------------------------------------------------ */
    /*  include_provenance_breakdown */
    /* ------------------------------------------------------------------ */

    public function test_include_provenance_breakdown_adds_a_three_tier_split(): void
    {
        $this->seedProvenance();
        $d = $this->distributor();

        $winchester = $this->master(['manufacturer' => 'Winchester', 'mfr_part_number' => 'PB-WIN']);
        $this->listing($winchester, $d, ['wholesale_price' => 9.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        $federal = $this->master(['manufacturer' => 'Federal', 'mfr_part_number' => 'PB-FED']);
        $this->listing($federal, $d, ['wholesale_price' => 7.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        $federalOos = $this->master(['manufacturer' => 'Federal', 'mfr_part_number' => 'PB-FED2']);
        $this->listing($federalOos, $d, ['wholesale_price' => 8.00, 'quantity_available' => 0, 'is_in_stock' => false]);

        // Unclassified, cheapest, in stock.
        $blazer = $this->master(['manufacturer' => 'Blazer', 'mfr_part_number' => 'PB-BLZ']);
        $this->listing($blazer, $d, ['wholesale_price' => 2.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        $data = $this->callApi([
            'api_key' => $this->apiKey(),
            'include_provenance_breakdown' => 1,
        ])->assertOk()->json();
        $nine = $data['calibers']['9mm Luger'];

        // Top-level numbers are still blended (unclassified included).
        $this->assertSame(4, $nine['total_catalog_offerings']);
        $this->assertSame(3, $nine['in_stock_count']);
        $this->assertEqualsWithDelta(0.04, $nine['lowest_cost_per_round']['raw'], 0.001);

        $breakdown = $nine['provenance_breakdown'];
        $this->assertSame(
            ['american_owned_american_made', 'foreign_owned_us_manufactured', 'imported_or_repackaged'],
            array_keys($breakdown),
        );

        $this->assertSame(1, $breakdown['american_owned_american_made']['in_stock_count']);
        $this->assertSame('100.0%', $breakdown['american_owned_american_made']['in_stock_percentage']);
        $this->assertEqualsWithDelta(0.18, $breakdown['american_owned_american_made']['lowest_cost_per_round']['raw'], 0.001);

        $this->assertSame(2, $breakdown['foreign_owned_us_manufactured']['total_catalog_offerings']);
        $this->assertSame(1, $breakdown['foreign_owned_us_manufactured']['in_stock_count']);
        $this->assertSame('50.0%', $breakdown['foreign_owned_us_manufactured']['in_stock_percentage']);
        $this->assertEqualsWithDelta(0.14, $breakdown['foreign_owned_us_manufactured']['lowest_cost_per_round']['raw'], 0.001);

        $this->assertSame(0, $breakdown['imported_or_repackaged']['total_catalog_offerings']);
        $this->assertNull($breakdown['imported_or_repackaged']['lowest_cost_per_round']['raw']);

        // The tier breakdown has no best_value_offering key (lean shape).
        $this->assertArrayNotHasKey('best_value_offering', $breakdown['american_owned_american_made']);
    }

    public function test_breakdown_is_absent_unless_requested(): void
    {
        $this->seedProvenance();
        $d = $this->distributor();
        $federal = $this->master(['manufacturer' => 'Federal']);
        $this->listing($federal, $d, ['quantity_available' => 100, 'is_in_stock' => true]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();

        $this->assertArrayNotHasKey('provenance_breakdown', $data['calibers']['9mm Luger']);
    }

    /* ------------------------------------------------------------------ */
    /*  unclassified_brands */
    /* ------------------------------------------------------------------ */

    public function test_unclassified_brands_lists_live_inventory_brands_with_no_mapping(): void
    {
        $this->seedProvenance();
        $d = $this->distributor();

        // Classified — must not appear.
        $this->listing($this->master(['manufacturer' => 'Federal']), $d, [
            'quantity_available' => 100, 'is_in_stock' => true,
        ]);

        // Unclassified, in stock — must appear (sorted).
        $this->listing($this->master(['manufacturer' => 'Monarch', 'mfr_part_number' => 'UC-MON']), $d, [
            'quantity_available' => 100, 'is_in_stock' => true,
        ]);
        $this->listing($this->master(['manufacturer' => 'Blazer', 'mfr_part_number' => 'UC-BLZ']), $d, [
            'quantity_available' => 100, 'is_in_stock' => true,
        ]);

        // Unclassified but out of stock — not "live inventory".
        $this->listing($this->master(['manufacturer' => 'Ghost Ammo', 'mfr_part_number' => 'UC-GHT']), $d, [
            'quantity_available' => 0, 'is_in_stock' => false,
        ]);

        // Unclassified but flagged — never surfaces in the public feed.
        $this->listing($this->master(['manufacturer' => 'Flagged Co', 'mfr_part_number' => 'UC-FLG']), $d, [
            'quantity_available' => 100, 'is_in_stock' => true,
            'needs_review' => true, 'review_reason' => 'bad parse',
        ]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();

        $this->assertSame(['Blazer', 'Monarch'], $data['unclassified_brands']);
    }

    public function test_unclassified_brands_are_blended_into_totals_but_not_into_any_tier(): void
    {
        $this->seedProvenance();
        $d = $this->distributor();

        $this->listing($this->master(['manufacturer' => 'Winchester', 'mfr_part_number' => 'BT-WIN']), $d, [
            'wholesale_price' => 9.00, 'quantity_available' => 100, 'is_in_stock' => true,
        ]);
        $this->listing($this->master(['manufacturer' => 'Blazer', 'mfr_part_number' => 'BT-BLZ']), $d, [
            'wholesale_price' => 2.00, 'quantity_available' => 100, 'is_in_stock' => true,
        ]);

        $data = $this->callApi([
            'api_key' => $this->apiKey(),
            'include_provenance_breakdown' => 1,
        ])->assertOk()->json();
        $nine = $data['calibers']['9mm Luger'];

        // Blended total counts both brands.
        $this->assertSame(2, $nine['in_stock_count']);

        // Every tier sees only its own classified brand; the three tiers
        // sum to 1, not 2 — the unclassified Blazer line is in none.
        $tierInStock = array_sum(array_map(
            fn ($tier) => $tier['in_stock_count'],
            $nine['provenance_breakdown'],
        ));
        $this->assertSame(1, $tierInStock);
        $this->assertSame(1, $nine['provenance_breakdown']['american_owned_american_made']['in_stock_count']);
        $this->assertSame(0, $nine['provenance_breakdown']['imported_or_repackaged']['in_stock_count']);

        $this->assertContains('Blazer', $data['unclassified_brands']);
        $this->assertNotContains('Winchester', $data['unclassified_brands']);
    }

    /* ------------------------------------------------------------------ */
    /*  Cache key variation */
    /* ------------------------------------------------------------------ */

    public function test_cache_keys_vary_by_query_parameters(): void
    {
        $d = $this->distributor();
        $master = $this->master(['mfr_part_number' => 'CK-1']);
        $this->listing($master, $d, ['wholesale_price' => 10.00, 'quantity_available' => 100, 'is_in_stock' => true]);
        $master2 = $this->master(['mfr_part_number' => 'CK-2']);
        $this->listing($master2, $d, ['wholesale_price' => 6.00, 'quantity_available' => 400, 'is_in_stock' => true]);

        $default = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $filtered = $this->callApi([
            'api_key' => $this->apiKey(),
            'min_stock_units' => 200,
        ])->assertOk()->json();

        // Distinct cache entries → the filter actually took effect rather
        // than replaying the default payload.
        $this->assertSame(2, $default['calibers']['9mm Luger']['total_catalog_offerings']);
        $this->assertSame(1, $filtered['calibers']['9mm Luger']['total_catalog_offerings']);

        // New data after both are cached…
        $this->listing($this->master(['mfr_part_number' => 'CK-3']), $d, [
            'wholesale_price' => 1.00, 'quantity_available' => 500, 'is_in_stock' => true,
        ]);

        // …each key still serves its own frozen payload.
        $this->assertSame(
            $default,
            $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json(),
        );
        $this->assertSame(
            $filtered,
            $this->callApi(['api_key' => $this->apiKey(), 'min_stock_units' => 200])->assertOk()->json(),
        );
    }
}
