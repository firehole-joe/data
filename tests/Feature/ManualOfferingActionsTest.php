<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Models\User;
use App\Services\Ammunition\SupplyReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ManualOfferingActionsTest extends TestCase
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
            'manufacturer' => 'Hornady',
            'mfr_part_number' => "H{$n}",
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
    /*  Manual flag */
    /* ----------------------------------------------------------------- */

    public function test_admin_can_manually_flag_a_clean_offering(): void
    {
        $offering = $this->listing($this->master(), $this->distributor());
        $this->assertFalse((bool) $offering->needs_review);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('supply.dashboard'))
            ->patch(route('supply.offerings.flag', $offering), ['review_reason' => 'Suspicious pack count'])
            ->assertRedirect(route('supply.dashboard'))
            ->assertSessionHas('success');

        $fresh = $offering->fresh();
        $this->assertTrue((bool) $fresh->needs_review);
        $this->assertSame('Suspicious pack count', $fresh->review_reason);
        $this->assertFalse((bool) $fresh->is_ignored);
    }

    public function test_manual_flag_falls_back_to_a_generic_reason(): void
    {
        $offering = $this->listing($this->master(), $this->distributor());

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('supply.offerings.flag', $offering))
            ->assertRedirect();

        $this->assertStringContainsString('administrator', (string) $offering->fresh()->review_reason);
    }

    public function test_non_admin_cannot_flag_without_the_passphrase(): void
    {
        $offering = $this->listing($this->master(), $this->distributor());

        $this->actingAs(User::factory()->create())
            ->patch(route('supply.offerings.flag', $offering))
            ->assertRedirect(route('admin.unlock'));

        $this->assertFalse((bool) $offering->fresh()->needs_review);
    }

    public function test_passphrase_unlocked_non_admin_can_flag(): void
    {
        config(['feed.admin_passphrase' => 'let-me-in']);
        $offering = $this->listing($this->master(), $this->distributor());

        $this->actingAs(User::factory()->create())
            ->withSession(['feed_admin_authenticated' => true])
            ->patch(route('supply.offerings.flag', $offering))
            ->assertRedirect();

        $this->assertTrue((bool) $offering->fresh()->needs_review);
    }

    /* ----------------------------------------------------------------- */
    /*  Manual round-count correction */
    /* ----------------------------------------------------------------- */

    public function test_admin_can_correct_the_round_count_from_the_drawer(): void
    {
        // Hornady H90254 — really a 500-round case that ingested as 50.
        $master = $this->master(['mfr_part_number' => 'H90254', 'rounds_per_box' => 50]);
        $offering = $this->listing($master, $this->distributor(), [
            'distributor_sku' => 'H90254',
            'wholesale_price' => 129.99,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('supply.offerings.approve', $offering), ['round_count' => 500])
            ->assertRedirect();

        $fresh = $offering->fresh();
        $this->assertSame(500, (int) $fresh->round_count, 'the offering now carries its true rounds-per-unit');
        $this->assertEqualsWithDelta(0.26, (float) $fresh->cost_per_round, 0.001, '129.99 / 500 = $0.26/rd');
        $this->assertFalse((bool) $fresh->needs_review);

        // Durable ledger row with the confirmed count.
        $this->assertDatabaseHas('distributor_sku_overrides', [
            'distributor_id' => $offering->distributor_id,
            'distributor_sku' => 'H90254',
            'round_count' => 500,
            'is_ignored' => false,
        ]);
    }

    public function test_correcting_a_flagged_offering_clears_the_review_flag(): void
    {
        $master = $this->master(['rounds_per_box' => 1000]);
        $offering = $this->listing($master, $this->distributor(), [
            'distributor_sku' => 'MG9A',
            'wholesale_price' => 12.88,
            'needs_review' => true,
            'review_reason' => 'below the centerfire floor',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('supply.offerings.approve', $offering), ['round_count' => 50])
            ->assertRedirect();

        $fresh = $offering->fresh();
        $this->assertFalse((bool) $fresh->needs_review);
        $this->assertNull($fresh->review_reason);
        $this->assertEqualsWithDelta(0.2576, (float) $fresh->cost_per_round, 0.0001);
        // The poisoned master is corrected back to the box count.
        $this->assertSame(50, (int) $master->fresh()->rounds_per_box);
    }

    /* ----------------------------------------------------------------- */
    /*  Manual ignore */
    /* ----------------------------------------------------------------- */

    public function test_admin_can_ignore_an_offering_from_the_drawer(): void
    {
        $master = $this->master();
        $offering = $this->listing($master, $this->distributor(), ['distributor_sku' => 'JUNK9']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('supply.offerings.ignore', $offering))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue((bool) $offering->fresh()->is_ignored);
        $this->assertDatabaseHas('distributor_sku_overrides', [
            'distributor_id' => $offering->distributor_id,
            'distributor_sku' => 'JUNK9',
            'is_ignored' => true,
        ]);

        // Gone from the catalog.
        $this->assertCount(0, $this->service->paginate($this->filters()));
    }

    /* ----------------------------------------------------------------- */
    /*  Drawer UI wiring */
    /* ----------------------------------------------------------------- */

    public function test_drawer_shows_the_edit_menu_and_action_forms_to_an_admin(): void
    {
        $master = $this->master();
        $offering = $this->listing($master, $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']), ['distributor_sku' => 'CLEAN9']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('supply.dashboard'))
            ->assertOk()
            ->assertSee('Save correction')
            ->assertSee('Flag for review')
            ->assertSee('Ignore SKU')
            ->assertSee('id="ammo-flag-'.$offering->id.'"', false)
            ->assertSee('action="'.route('supply.offerings.flag', $offering), false);
    }

    public function test_drawer_edit_menu_is_hidden_from_guests(): void
    {
        $this->listing($this->master(), $this->distributor(['slug' => 'rsr']), ['distributor_sku' => 'CLEAN9']);

        $this->get(route('supply.dashboard'))
            ->assertOk()
            ->assertSee('ok')                 // the read-only status is still shown
            ->assertDontSee('Save correction')
            ->assertDontSee('Flag for review')
            ->assertDontSee('id="ammo-flag-', false);
    }

    /* ----------------------------------------------------------------- */
    /*  Granular packaging quick-filter pills */
    /* ----------------------------------------------------------------- */

    private function seedPackSizes(): void
    {
        $d = $this->distributor(['slug' => 'rsr']);

        $this->listing($this->master(['name' => 'RIFLE 20', 'caliber' => '5.56x45mm NATO', 'rounds_per_box' => 20]), $d);
        $this->listing($this->master(['name' => 'PISTOL 50', 'rounds_per_box' => 50]), $d);
        $this->listing($this->master(['name' => 'PISTOL 50 B', 'rounds_per_box' => 50]), $d);
        $this->listing($this->master(['name' => 'MINI BULK 100', 'rounds_per_box' => 100]), $d);
        $this->listing($this->master(['name' => 'BRICK 500', 'caliber' => '.22 LR', 'rounds_per_box' => 500]), $d);
        $this->listing($this->master(['name' => 'CASE 1000', 'rounds_per_box' => 1000]), $d);
        $this->listing($this->master(['name' => 'CASE 1200', 'rounds_per_box' => 1200]), $d);
    }

    public function test_granular_pills_filter_query_results_to_the_exact_pack_size(): void
    {
        $this->seedPackSizes();

        $this->assertSame(['RIFLE 20'], $this->names(['packaging' => '20']));
        $this->assertSame(['PISTOL 50', 'PISTOL 50 B'], $this->names(['packaging' => '50']));
        $this->assertSame(['MINI BULK 100'], $this->names(['packaging' => '100']));
        $this->assertSame(['BRICK 500'], $this->names(['packaging' => '500']));
        // "1000" is a "1000 or more" bucket (full case).
        $this->assertSame(['CASE 1000', 'CASE 1200'], $this->names(['packaging' => '1000']));
    }

    public function test_granular_pill_facet_counts_are_accurate_and_scope_aware(): void
    {
        $this->seedPackSizes();

        $bySize = $this->service->facets($this->filters())['packaging']['by_size'];

        $this->assertSame(1, $bySize[20]);
        $this->assertSame(2, $bySize[50]);
        $this->assertSame(1, $bySize[100]);
        $this->assertSame(1, $bySize[500]);
        $this->assertSame(2, $bySize[1000], '1000 and 1200 both fall in the full-case bucket');

        // Selecting one pill does not collapse the others' counts…
        $scoped = $this->service->facets($this->filters(['packaging' => '50']))['packaging']['by_size'];
        $this->assertSame(2, $scoped[50]);
        $this->assertSame(2, $scoped[1000]);

        // …but a caliber filter does cascade into them.
        $rimfire = $this->service->facets($this->filters(['calibers' => ['.22 LR']]))['packaging']['by_size'];
        $this->assertSame(1, $rimfire[500]);
        $this->assertSame(0, $rimfire[50]);
    }

    public function test_granular_pill_round_trips_through_the_dashboard_with_a_clear_pill(): void
    {
        $this->seedPackSizes();

        $this->get(route('supply.dashboard', ['packaging' => '500']))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['packaging'] === '500')
            ->assertViewHas('masters', fn ($m) => $m->pluck('name')->all() === ['BRICK 500'])
            // The active-filter pill bar renders a clearable pill.
            ->assertSee('500 rounds')
            ->assertSee('data-pill-name="packaging"', false);
    }

    public function test_numeric_packaging_survives_the_session_round_trip(): void
    {
        $this->seedPackSizes();

        // First request stores it in the session…
        $this->get(route('supply.dashboard', ['packaging' => '100']))->assertOk();

        // …a bare visit is redirected back onto it.
        $this->get(route('supply.dashboard'))
            ->assertRedirect();

        $this->followingRedirects()
            ->get(route('supply.dashboard'))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['packaging'] === '100');
    }
}
