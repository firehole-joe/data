<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\SupplyReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SupplyReportFilteringTest extends TestCase
{
    use RefreshDatabase;

    private SupplyReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SupplyReportQueryService::class);
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
            'upc' => null,
            'manufacturer' => 'Federal',
            'mfr_part_number' => "MPN-{$n}",
            'name' => "Master Ammo {$n}",
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function filters(array $input = []): array
    {
        return $this->service->normalizeFilters(new Request($input));
    }

    /* ----------------------------------------------------------------- */
    /* Filtering */
    /* ----------------------------------------------------------------- */

    public function test_distributor_scope_supports_inclusion_and_exclusion(): void
    {
        $rsr = $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']);
        $lip = $this->distributor(['name' => 'Lipseys', 'slug' => 'lipseys']);

        $a = $this->master(['name' => 'RSR ONLY ITEM']);
        $b = $this->master(['name' => 'LIPSEYS ONLY ITEM', 'mfr_part_number' => 'L1']);
        $this->listing($a, $rsr);
        $this->listing($b, $lip);

        $include = $this->service->paginate($this->filters(['distributors' => [$rsr->id]]));
        $this->assertEqualsCanonicalizing(['RSR ONLY ITEM'], $include->pluck('name')->all());

        $exclude = $this->service->paginate($this->filters([
            'distributors' => [$rsr->id],
            'distributor_mode' => 'exclude',
        ]));
        $this->assertEqualsCanonicalizing(['LIPSEYS ONLY ITEM'], $exclude->pluck('name')->all());

        $this->assertSame('RSR Group', $this->service->scopeLabel($this->filters(['distributors' => [$rsr->id]])));
        $this->assertSame(
            'All except RSR Group',
            $this->service->scopeLabel($this->filters(['distributors' => [$rsr->id], 'distributor_mode' => 'exclude'])),
        );
    }

    public function test_caliber_projectile_and_grain_filters_are_multi_select(): void
    {
        $d = $this->distributor();
        $nine = $this->master(['caliber' => '9mm Luger', 'bullet_type' => 'FMJ', 'bullet_weight_gr' => 115, 'name' => 'NINE FMJ 115']);
        $fiftySix = $this->master(['caliber' => '5.56x45mm NATO', 'bullet_type' => 'OTM', 'bullet_weight_gr' => 77, 'name' => 'FIFTYSIX OTM 77', 'mfr_part_number' => 'R1']);
        $fortyFive = $this->master(['caliber' => '.45 ACP', 'bullet_type' => 'JHP', 'bullet_weight_gr' => 230, 'name' => 'FORTYFIVE JHP 230', 'mfr_part_number' => 'R2']);
        $this->listing($nine, $d);
        $this->listing($fiftySix, $d);
        $this->listing($fortyFive, $d);

        $byCaliber = $this->service->paginate($this->filters(['calibers' => ['9mm Luger', '.45 ACP']]));
        $this->assertEqualsCanonicalizing(['NINE FMJ 115', 'FORTYFIVE JHP 230'], $byCaliber->pluck('name')->all());

        $byProjectile = $this->service->paginate($this->filters(['projectile_types' => ['OTM']]));
        $this->assertEqualsCanonicalizing(['FIFTYSIX OTM 77'], $byProjectile->pluck('name')->all());

        $byGrain = $this->service->paginate($this->filters(['grain_weights' => [115, 230]]));
        $this->assertEqualsCanonicalizing(['NINE FMJ 115', 'FORTYFIVE JHP 230'], $byGrain->pluck('name')->all());
    }

    public function test_stock_status_and_min_qty_filters(): void
    {
        $d = $this->distributor();
        $live = $this->master(['name' => 'LIVE ITEM']);
        $dead = $this->master(['name' => 'DEAD ITEM', 'mfr_part_number' => 'D1']);
        $thin = $this->master(['name' => 'THIN ITEM', 'mfr_part_number' => 'T1']);

        $this->listing($live, $d, ['is_in_stock' => true, 'quantity_available' => 500]);
        $this->listing($dead, $d, ['is_in_stock' => false, 'quantity_available' => 0]);
        $this->listing($thin, $d, ['is_in_stock' => true, 'quantity_available' => 5]);

        $inStock = $this->service->paginate($this->filters(['stock_status' => 'in_stock']));
        $this->assertEqualsCanonicalizing(['LIVE ITEM', 'THIN ITEM'], $inStock->pluck('name')->all());

        $outOfStock = $this->service->paginate($this->filters(['stock_status' => 'out_of_stock']));
        $this->assertEqualsCanonicalizing(['DEAD ITEM'], $outOfStock->pluck('name')->all());

        $all = $this->service->paginate($this->filters(['stock_status' => 'all']));
        $this->assertCount(3, $all);

        $minQty = $this->service->paginate($this->filters(['min_qty' => 100]));
        $this->assertEqualsCanonicalizing(['LIVE ITEM'], $minQty->pluck('name')->all());
    }

    public function test_search_matches_upc_sku_mpn_brand_and_name(): void
    {
        $d = $this->distributor();
        $target = $this->master([
            'name' => 'TARGET MATCH LOAD',
            'manufacturer' => 'Hornady',
            'mfr_part_number' => 'HRN-8099',
            'upc' => '090255080995',
        ]);
        $other = $this->master(['name' => 'DECOY LOAD', 'manufacturer' => 'Federal', 'mfr_part_number' => 'FED-1', 'upc' => '111111111111']);

        $this->listing($target, $d, ['distributor_sku' => 'DIST-XYZ-1', 'raw_mfr_part_number' => 'HRN-8099', 'raw_upc' => '090255080995']);
        $this->listing($other, $d, ['distributor_sku' => 'DIST-AAA-2']);

        foreach (['090255080995', 'DIST-XYZ-1', 'HRN-8099', 'Hornady', 'TARGET MATCH'] as $term) {
            $rows = $this->service->paginate($this->filters(['search' => $term]));
            $this->assertEqualsCanonicalizing(['TARGET MATCH LOAD'], $rows->pluck('name')->all(), "search term: {$term}");
        }
    }

    public function test_per_page_is_validated_and_applied(): void
    {
        $d = $this->distributor();
        foreach (range(1, 30) as $i) {
            $this->listing($this->master(['name' => sprintf('ITEM %03d', $i), 'mfr_part_number' => "P{$i}"]), $d);
        }

        $this->assertSame(50, $this->filters(['per_page' => 999])['per_page']);
        $this->assertSame(25, $this->filters(['per_page' => 25])['per_page']);

        $page = $this->service->paginate($this->filters(['per_page' => 25]));
        $this->assertCount(25, $page);
        $this->assertSame(30, $page->total());
    }

    public function test_sorting_by_computed_columns(): void
    {
        $d = $this->distributor();
        $cheap = $this->master(['name' => 'CHEAP', 'mfr_part_number' => 'C1', 'rounds_per_box' => 50]);
        $mid = $this->master(['name' => 'MID', 'mfr_part_number' => 'M1', 'rounds_per_box' => 50]);
        $dear = $this->master(['name' => 'DEAR', 'mfr_part_number' => 'D1', 'rounds_per_box' => 50]);

        $this->listing($cheap, $d, ['wholesale_price' => 5.00, 'quantity_available' => 10]);
        $this->listing($mid, $d, ['wholesale_price' => 15.00, 'quantity_available' => 50]);
        $this->listing($dear, $d, ['wholesale_price' => 25.00, 'quantity_available' => 30]);

        $asc = $this->service->paginate($this->filters(['sort_by' => 'best_price', 'sort_dir' => 'asc']));
        $this->assertSame(['CHEAP', 'MID', 'DEAR'], $asc->pluck('name')->all());

        $desc = $this->service->paginate($this->filters(['sort_by' => 'best_cpr', 'sort_dir' => 'desc']));
        $this->assertSame(['DEAR', 'MID', 'CHEAP'], $desc->pluck('name')->all());

        $byQty = $this->service->paginate($this->filters(['sort_by' => 'total_qty', 'sort_dir' => 'desc']));
        $this->assertSame(['MID', 'DEAR', 'CHEAP'], $byQty->pluck('name')->all());
    }

    /* ----------------------------------------------------------------- */
    /* Aggregated stat cards */
    /* ----------------------------------------------------------------- */

    public function test_stats_are_computed_against_the_active_filtered_dataset(): void
    {
        $rsr = $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']);
        $lip = $this->distributor(['name' => 'Lipseys', 'slug' => 'lipseys']);

        // 9mm, two in-stock offerings on RSR: box prices 12.50 & 9.00 over 50 rd.
        $nine = $this->master(['caliber' => '9mm Luger', 'rounds_per_box' => 50]);
        $this->listing($nine, $rsr, ['wholesale_price' => 12.50, 'quantity_available' => 200, 'is_in_stock' => true]);
        $this->listing($nine, $rsr, ['wholesale_price' => 9.00, 'quantity_available' => 100, 'is_in_stock' => true]);

        // 5.56 on RSR, in stock: 20.00 over 20 rd => 1.00 / rd.
        $rifle = $this->master(['caliber' => '5.56x45mm NATO', 'rounds_per_box' => 20, 'mfr_part_number' => 'R1']);
        $this->listing($rifle, $rsr, ['wholesale_price' => 20.00, 'quantity_available' => 50, 'is_in_stock' => true]);

        // A .45 on RSR that is entirely out of stock.
        $fortyFive = $this->master(['caliber' => '.45 ACP', 'rounds_per_box' => 50, 'mfr_part_number' => 'R2']);
        $this->listing($fortyFive, $rsr, ['wholesale_price' => 30.00, 'quantity_available' => 0, 'is_in_stock' => false]);

        // Noise on Lipseys that must be excluded by the distributor scope.
        $this->listing($this->master(['mfr_part_number' => 'R3']), $lip, ['wholesale_price' => 1.00, 'quantity_available' => 9999]);

        $stats = $this->service->stats($this->filters([
            'distributors' => [$rsr->id],
            'stock_status' => 'all',
        ]));

        $this->assertSame('RSR Group', $stats['scope_label']);
        $this->assertSame(3, $stats['total_skus']);
        $this->assertSame(2, $stats['in_stock_skus']);
        $this->assertSame(1, $stats['out_of_stock_skus']);
        $this->assertSame(66.7, $stats['in_stock_pct']);

        // (200 + 100) * 50 + 50 * 20 + 0 * 50 = 15000 + 1000
        $this->assertSame(16000, $stats['pipeline_rounds']);
        $this->assertSame(350, $stats['pipeline_boxes']);

        $this->assertSame(9.00, $stats['box_price']['min']);
        $this->assertSame(30.00, $stats['box_price']['max']);

        // CPR: 9.00/50 = 0.18 low; 20.00/20 = 1.00 high.
        $this->assertSame(0.18, $stats['cpr']['min']);
        $this->assertSame(1.0, $stats['cpr']['max']);
    }

    public function test_stats_zero_out_cleanly_when_nothing_matches(): void
    {
        $this->master(['caliber' => '9mm Luger']); // tracked, no listings

        $stats = $this->service->stats($this->filters(['calibers' => ['9mm Luger']]));

        $this->assertSame('All Distributors', $stats['scope_label']);
        $this->assertSame(0, $stats['total_skus']);
        $this->assertSame(0, $stats['in_stock_skus']);
        $this->assertSame(0.0, $stats['in_stock_pct']);
        $this->assertSame(0, $stats['pipeline_rounds']);
        $this->assertNull($stats['cpr']['min']);
        $this->assertNull($stats['box_price']['max']);
    }

    /* ----------------------------------------------------------------- */
    /* Multi-distributor grouping */
    /* ----------------------------------------------------------------- */

    public function test_one_master_with_many_distributors_is_a_single_row_with_offerings(): void
    {
        $rsr = $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']);
        $lip = $this->distributor(['name' => 'Lipseys', 'slug' => 'lipseys']);
        $cro = $this->distributor(['name' => 'Crow', 'slug' => 'crow']);

        $master = $this->master(['name' => 'SHARED SKU', 'rounds_per_box' => 50]);
        $this->listing($master, $rsr, ['wholesale_price' => 14.00, 'quantity_available' => 40, 'is_in_stock' => true, 'distributor_sku' => 'RSR-1']);
        $this->listing($master, $lip, ['wholesale_price' => 11.50, 'quantity_available' => 60, 'is_in_stock' => true, 'distributor_sku' => 'LIP-1']);
        $this->listing($master, $cro, ['wholesale_price' => 13.00, 'quantity_available' => 0, 'is_in_stock' => false, 'distributor_sku' => 'CRW-1']);

        $rows = $this->service->paginate($this->filters(['stock_status' => 'all']));

        $this->assertCount(1, $rows);
        $row = $rows->first();

        $this->assertSame(11.50, $row->best_price_per_box);
        $this->assertSame(0.23, $row->best_price_per_round);
        $this->assertSame('Lipseys', $row->best_distributor_name);
        $this->assertSame(100, $row->total_quantity_available);
        $this->assertSame(3, $row->listing_count);
        $this->assertEqualsCanonicalizing(['Crow', 'Lipseys', 'RSR Group'], $row->distributor_badges->pluck('name')->all());
        $this->assertCount(3, $row->offerings);
    }

    public function test_in_stock_filter_narrows_the_offerings_on_the_parent_row(): void
    {
        $rsr = $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']);
        $lip = $this->distributor(['name' => 'Lipseys', 'slug' => 'lipseys']);

        $master = $this->master(['name' => 'SHARED SKU', 'rounds_per_box' => 50]);
        $this->listing($master, $rsr, ['wholesale_price' => 8.00, 'quantity_available' => 0, 'is_in_stock' => false]);
        $this->listing($master, $lip, ['wholesale_price' => 12.00, 'quantity_available' => 40, 'is_in_stock' => true]);

        $rows = $this->service->paginate($this->filters(['stock_status' => 'in_stock']));

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(1, $row->listing_count);
        $this->assertSame(12.00, $row->best_price_per_box);
        $this->assertSame('Lipseys', $row->best_distributor_name);
    }

    /* ----------------------------------------------------------------- */
    /* Dashboard route */
    /* ----------------------------------------------------------------- */

    public function test_dashboard_route_renders_stat_cards_filters_and_table(): void
    {
        $rsr = $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']);
        $master = $this->master(['name' => 'DASHBOARD ITEM', 'caliber' => '9mm Luger']);
        $this->listing($master, $rsr, ['wholesale_price' => 12.00, 'quantity_available' => 250]);

        $this->get(route('supply.dashboard'))
            ->assertOk()
            ->assertSee('Ammunition Supply Dashboard')
            ->assertSee('In-Stock Health')
            ->assertSee('Total Pipeline Rounds')
            ->assertSee('Price Spread')
            ->assertSee('All Distributors')
            ->assertSee('RSR Group')
            ->assertSee('DASHBOARD ITEM')
            ->assertSee('In Stock Only');
    }

    public function test_dashboard_route_applies_query_string_filters(): void
    {
        $d = $this->distributor();
        $this->listing($this->master(['name' => 'NINE ITEM', 'caliber' => '9mm Luger']), $d);
        $this->listing($this->master(['name' => 'RIFLE ITEM', 'caliber' => '5.56x45mm NATO', 'mfr_part_number' => 'R1']), $d);

        $this->get(route('supply.dashboard', ['calibers' => ['9mm Luger']]))
            ->assertOk()
            ->assertSee('NINE ITEM')
            ->assertDontSee('RIFLE ITEM');
    }

    /* ----------------------------------------------------------------- */
    /* Cascading filter facets */
    /* ----------------------------------------------------------------- */

    /**
     * @return array<int, MasterAmmunition>
     */
    private function seedCascadeCatalogue(Distributor $distributor): array
    {
        $a = $this->master(['caliber' => '9mm Luger', 'bullet_type' => 'FMJ', 'bullet_weight_gr' => 115, 'mfr_part_number' => 'C-A']);
        $b = $this->master(['caliber' => '9mm Luger', 'bullet_type' => 'JHP', 'bullet_weight_gr' => 124, 'mfr_part_number' => 'C-B']);
        $c = $this->master(['caliber' => '9mm Luger', 'bullet_type' => 'JHP', 'bullet_weight_gr' => 147, 'mfr_part_number' => 'C-C']);
        $d = $this->master(['caliber' => '.45 ACP', 'bullet_type' => 'FMJ', 'bullet_weight_gr' => 230, 'mfr_part_number' => 'C-D']);

        foreach ([$a, $b, $c, $d] as $master) {
            $this->listing($master, $distributor);
        }

        return [$a, $b, $c, $d];
    }

    public function test_caliber_selection_constrains_available_grain_weights(): void
    {
        $this->seedCascadeCatalogue($this->distributor());

        $all = $this->service->facets($this->filters());
        $this->assertEqualsCanonicalizing([115, 124, 147, 230], array_keys($all['grain_weights']));

        $nine = $this->service->facets($this->filters(['calibers' => ['9mm Luger']]));
        $this->assertEqualsCanonicalizing([115, 124, 147], array_keys($nine['grain_weights']));
        $this->assertArrayNotHasKey(230, $nine['grain_weights']);
    }

    public function test_projectile_facet_follows_caliber_and_grain_facet_follows_projectile(): void
    {
        $this->seedCascadeCatalogue($this->distributor());

        // Projectile options are scoped by the selected caliber only.
        $nine = $this->service->facets($this->filters(['calibers' => ['9mm Luger']]));
        $this->assertEqualsCanonicalizing(['FMJ', 'JHP'], array_keys($nine['projectile_types']));

        // Grain options are scoped by caliber *and* projectile.
        $nineJhp = $this->service->facets($this->filters([
            'calibers' => ['9mm Luger'],
            'projectile_types' => ['JHP'],
        ]));
        $this->assertEqualsCanonicalizing([124, 147], array_keys($nineJhp['grain_weights']));
        $this->assertArrayNotHasKey(115, $nineJhp['grain_weights']);

        // The projectile facet itself is NOT narrowed by its own selection.
        $this->assertEqualsCanonicalizing(['FMJ', 'JHP'], array_keys($nineJhp['projectile_types']));
    }

    public function test_caliber_facet_is_not_narrowed_by_its_own_selection_and_reports_counts(): void
    {
        $this->seedCascadeCatalogue($this->distributor());

        $facets = $this->service->facets($this->filters(['calibers' => ['9mm Luger']]));

        $this->assertEqualsCanonicalizing(['9mm Luger', '.45 ACP'], array_keys($facets['calibers']));
        $this->assertSame(3, $facets['calibers']['9mm Luger']);
        $this->assertSame(1, $facets['calibers']['.45 ACP']);

        // Projectile badge counts reflect the 9mm cascade: 1 FMJ + 2 JHP SKUs.
        $this->assertSame(1, $facets['projectile_types']['FMJ']);
        $this->assertSame(2, $facets['projectile_types']['JHP']);
    }

    public function test_facets_respect_distributor_and_stock_scope(): void
    {
        $rsr = $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']);
        $lip = $this->distributor(['name' => 'Lipseys', 'slug' => 'lipseys']);

        $this->listing($this->master(['caliber' => '9mm Luger', 'mfr_part_number' => 'S-A']), $rsr, ['is_in_stock' => true, 'quantity_available' => 100]);
        $this->listing($this->master(['caliber' => '10mm Auto', 'mfr_part_number' => 'S-B']), $rsr, ['is_in_stock' => false, 'quantity_available' => 0]);
        $this->listing($this->master(['caliber' => '.45 ACP', 'mfr_part_number' => 'S-C']), $lip, ['is_in_stock' => true, 'quantity_available' => 100]);

        $rsrOnly = $this->service->facets($this->filters(['distributors' => [$rsr->id]]));
        $this->assertEqualsCanonicalizing(['9mm Luger', '10mm Auto'], array_keys($rsrOnly['calibers']));

        $rsrInStock = $this->service->facets($this->filters([
            'distributors' => [$rsr->id],
            'stock_status' => 'in_stock',
        ]));
        $this->assertEqualsCanonicalizing(['9mm Luger'], array_keys($rsrInStock['calibers']));
    }

    public function test_dashboard_view_data_carries_cascading_facets_and_accordion_scaffolding(): void
    {
        $d = $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']);
        $this->seedCascadeCatalogue($d);

        $response = $this->get(route('supply.dashboard', ['calibers' => ['9mm Luger']]))->assertOk();

        $facets = $response->viewData('facets');
        $this->assertArrayHasKey('calibers', $facets);
        $this->assertArrayHasKey('projectile_types', $facets);
        $this->assertArrayHasKey(115, $facets['grain_weights']);
        $this->assertArrayNotHasKey(230, $facets['grain_weights']);

        $response->assertSee('Projectile Type')
            ->assertSee('Grain Weight')
            ->assertSee('Clear All')
            ->assertSee('data-accordion', false);
    }
}
