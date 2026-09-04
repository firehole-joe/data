<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PublicSupplyReportController;
use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
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

        // The endpoint caches its response under a fixed key; forget it
        // before every test so one test's seeded data can never leak
        // into the next via a stale cached payload.
        Cache::forget(PublicSupplyReportController::CACHE_KEY);
    }

    private function apiKey(): string
    {
        return (string) config('services.reports.api_key');
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
                'meta' => ['generated_at', 'generated_at_human', 'distributors_active'],
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

    public function test_357_magnum_allows_both_fmj_and_sp_but_not_other_projectiles(): void
    {
        $d = $this->distributor();

        $fmj = $this->master(['mfr_part_number' => 'MAG-FMJ', 'caliber' => '.357 Magnum', 'bullet_type' => 'FMJ']);
        $this->listing($fmj, $d, ['wholesale_price' => 12.00, 'quantity_available' => 20, 'is_in_stock' => true]);

        $sp = $this->master(['mfr_part_number' => 'MAG-SP', 'caliber' => '.357 Magnum', 'bullet_type' => 'SP']);
        $this->listing($sp, $d, ['wholesale_price' => 11.00, 'quantity_available' => 20, 'is_in_stock' => true]);

        // Cheaper hollow-point load — a standard training/target FMJ/SP
        // report must not surface this as the "lowest" price.
        $hp = $this->master(['mfr_part_number' => 'MAG-HP', 'caliber' => '.357 Magnum', 'bullet_type' => 'HP']);
        $this->listing($hp, $d, ['wholesale_price' => 5.00, 'quantity_available' => 20, 'is_in_stock' => true]);

        $data = $this->callApi(['api_key' => $this->apiKey()])->assertOk()->json();
        $magnum = $data['calibers']['.357 Magnum'];

        $this->assertSame(2, $magnum['total_catalog_offerings'], 'only the FMJ and SP lines count');
        // Cheapest of the two allowed loads: $11.00 / 50 = $0.22/rd.
        $this->assertEqualsWithDelta(0.22, $magnum['lowest_cost_per_round']['raw'], 0.001);
        $this->assertSame('SP', $magnum['best_value_offering']['bullet_type']);
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
        $this->assertTrue(Cache::has(PublicSupplyReportController::CACHE_KEY));
    }
}
