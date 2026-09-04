<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\DistributorSkuOverride;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\SupplyReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SupplyDashboardFilterTest extends TestCase
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
            'wholesale_price' => 12.00,
            'quantity_available' => 100,
            'is_in_stock' => true,
        ], $overrides));
    }

    private function filters(array $input = []): array
    {
        return $this->service->normalizeFilters(new Request($input));
    }

    private function names(array $input): array
    {
        return $this->service->paginate($this->filters($input))->pluck('name')->sort()->values()->all();
    }

    /* ----------------------------------------------------------------- */
    /*  Dynamic review filter visibility */
    /* ----------------------------------------------------------------- */

    public function test_review_filter_block_is_hidden_when_nothing_is_flagged(): void
    {
        $d = $this->distributor();
        $this->listing($this->master(), $d); // clean offering, no needs_review

        $response = $this->get(route('supply.dashboard'))->assertOk();

        $this->assertSame(0, $response->viewData('flaggedCount'));
        $this->assertFalse($response->viewData('showReviewFilter'));
        $response->assertDontSee('aria-label="Review status"', false);
        // The Packaging block is always available.
        $response->assertSee('aria-label="Pack size"', false);
    }

    public function test_review_filter_block_appears_when_a_flagged_offering_exists(): void
    {
        $d = $this->distributor();
        $this->listing($this->master(), $d, ['needs_review' => true, 'review_reason' => 'out of band']);

        $response = $this->get(route('supply.dashboard'))->assertOk();

        $this->assertSame(1, $response->viewData('flaggedCount'));
        $this->assertTrue($response->viewData('showReviewFilter'));
        $response->assertSee('aria-label="Review status"', false);
    }

    public function test_review_filter_block_appears_when_the_user_is_filtering_on_review_even_with_nothing_flagged(): void
    {
        $d = $this->distributor();
        $this->listing($this->master(), $d); // clean

        $response = $this->get(route('supply.dashboard', ['review' => 'clean']))->assertOk();

        $this->assertSame(0, $response->viewData('flaggedCount'));
        $this->assertTrue($response->viewData('showReviewFilter'));
        $response->assertSee('aria-label="Review status"', false);
    }

    public function test_an_ignored_flagged_offering_does_not_keep_the_review_block_open(): void
    {
        $d = $this->distributor();
        // Flagged but reviewer-dismissed → does not count toward the catalog total.
        $this->listing($this->master(), $d, ['needs_review' => true, 'is_ignored' => true]);

        $response = $this->get(route('supply.dashboard'))->assertOk();

        $this->assertSame(0, $response->viewData('flaggedCount'));
        $response->assertDontSee('aria-label="Review status"', false);
    }

    /* ----------------------------------------------------------------- */
    /*  Packaging / pack-size filter */
    /* ----------------------------------------------------------------- */

    private function seedPackSizes(): void
    {
        $d = $this->distributor(['slug' => 'rsr']);

        // Standard retail boxes (<= 50).
        $this->listing($this->master(['name' => 'BOX 20', 'caliber' => '5.56x45mm NATO', 'rounds_per_box' => 20]), $d);
        $this->listing($this->master(['name' => 'BOX 50', 'rounds_per_box' => 50]), $d);
        $this->listing($this->master(['name' => 'RIMFIRE BOX 50', 'caliber' => '.22 LR', 'rounds_per_box' => 50]), $d);

        // Bulk / cases (>= 100).
        $this->listing($this->master(['name' => 'CASE 1000', 'rounds_per_box' => 1000]), $d);
        $this->listing($this->master(['name' => 'RIMFIRE BRICK 525', 'caliber' => '.22 LR', 'rounds_per_box' => 525]), $d);
        $this->listing($this->master(['name' => 'PACK 100', 'rounds_per_box' => 100]), $d);
    }

    public function test_packaging_standard_returns_only_boxes_of_50_or_fewer(): void
    {
        $this->seedPackSizes();

        $this->assertSame(
            ['BOX 20', 'BOX 50', 'RIMFIRE BOX 50'],
            $this->names(['packaging' => 'standard']),
        );
    }

    public function test_packaging_bulk_returns_only_packs_of_100_or_more(): void
    {
        $this->seedPackSizes();

        $this->assertSame(
            ['CASE 1000', 'PACK 100', 'RIMFIRE BRICK 525'],
            $this->names(['packaging' => 'bulk']),
        );
    }

    public function test_packaging_all_is_the_default_and_returns_everything(): void
    {
        $this->seedPackSizes();

        $this->assertCount(6, $this->service->paginate($this->filters()));
        $this->assertCount(6, $this->service->paginate($this->filters(['packaging' => 'all'])));
        $this->assertSame('all', $this->filters(['packaging' => 'garbage'])['packaging']);
    }

    public function test_pack_size_alias_is_accepted(): void
    {
        $this->seedPackSizes();

        $this->assertSame(['BOX 20', 'BOX 50', 'RIMFIRE BOX 50'], $this->names(['pack_size' => 'standard']));
    }

    public function test_packaging_uses_the_offerings_own_round_count_over_a_poisoned_master(): void
    {
        $d = $this->distributor(['slug' => 'rsr']);

        // Master poisoned to a case count, but this offering is a 50-round box.
        $master = $this->master(['name' => 'POISONED', 'rounds_per_box' => 1000]);
        $this->listing($master, $d, ['distributor_sku' => 'BOX9', 'round_count' => 50]);

        $this->assertSame(['POISONED'], $this->names(['packaging' => 'standard']));
        $this->assertSame([], $this->names(['packaging' => 'bulk']));
    }

    public function test_packaging_respects_a_confirmed_sku_override_count(): void
    {
        $d = $this->distributor(['slug' => 'rsr']);

        $master = $this->master(['name' => 'OVERRIDDEN', 'rounds_per_box' => 1000]);
        $offering = $this->listing($master, $d, ['distributor_sku' => 'OV9', 'round_count' => null]);

        DistributorSkuOverride::create([
            'distributor_id' => $d->id,
            'distributor_sku' => 'OV9',
            'round_count' => 50,
            'is_ignored' => false,
        ]);

        $this->assertSame(['OVERRIDDEN'], $this->names(['packaging' => 'standard']));
    }

    public function test_numeric_packaging_values_are_exact_and_1000_means_or_more(): void
    {
        $this->seedPackSizes();

        $this->assertSame(['BOX 20'], $this->names(['packaging' => '20']));
        $this->assertSame(['CASE 1000'], $this->names(['packaging' => '1000']));
    }

    public function test_packaging_facet_counts_are_scoped_and_do_not_collapse(): void
    {
        $this->seedPackSizes();

        $facets = $this->service->facets($this->filters(['packaging' => 'standard']));

        // Both chips still report their full count under the active scope.
        $this->assertSame(3, $facets['packaging']['standard']);
        $this->assertSame(3, $facets['packaging']['bulk']);

        // Narrowing by caliber cascades into the packaging counts.
        $rimfire = $this->service->facets($this->filters(['calibers' => ['.22 LR']]));
        $this->assertSame(1, $rimfire['packaging']['standard']);
        $this->assertSame(1, $rimfire['packaging']['bulk']);
    }

    public function test_packaging_filter_round_trips_through_the_dashboard_route(): void
    {
        $this->seedPackSizes();

        $this->get(route('supply.dashboard', ['packaging' => 'bulk']))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['packaging'] === 'bulk')
            ->assertViewHas('masters', fn ($m) => $m->pluck('name')->sort()->values()->all()
                === ['CASE 1000', 'PACK 100', 'RIMFIRE BRICK 525'])
            ->assertSee('Standard Boxes')
            ->assertSee('Bulk / Cases');
    }
}
