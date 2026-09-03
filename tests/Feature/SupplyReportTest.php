<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\FeedRun;
use App\Models\MasterAmmunition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyReportTest extends TestCase
{
    use RefreshDatabase;

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

    /* ---------------------------------------------------------------- */

    public function test_index_renders_with_branding_and_kpi_cards(): void
    {
        $this->listing($this->master(), $this->distributor());

        $this->get(route('supply.index'))
            ->assertOk()
            ->assertSee('2026 Ammunition Supply Report')
            ->assertSee('data.firehole.com')
            ->assertSee('Tracked Master SKUs')
            ->assertSee('Lowest 9mm $/rd')
            ->assertSee('Lowest 5.56 $/rd')
            ->assertSee('Rounds in Supply Chain')
            ->assertSee('Best $ / Round')
            ->assertSee('In Stock');
    }

    public function test_caliber_filter_limits_the_result_set(): void
    {
        $distributor = $this->distributor();
        $this->listing($this->master(['caliber' => '9mm Luger', 'name' => 'NINE PARABELLUM ITEM']), $distributor);
        $this->listing($this->master([
            'caliber' => '5.56x45mm NATO',
            'name' => 'FIFTYSIX CARBINE ITEM',
            'manufacturer' => 'PMC',
            'mfr_part_number' => 'R1',
        ]), $distributor);

        $this->get(route('supply.index', ['caliber' => '9mm Luger']))
            ->assertOk()
            ->assertSee('NINE PARABELLUM ITEM')
            ->assertDontSee('FIFTYSIX CARBINE ITEM');

        $this->get(route('supply.index', ['caliber' => '5.56x45mm NATO']))
            ->assertOk()
            ->assertSee('FIFTYSIX CARBINE ITEM')
            ->assertDontSee('NINE PARABELLUM ITEM');
    }

    public function test_manufacturer_filter(): void
    {
        $distributor = $this->distributor();
        $this->listing($this->master(['manufacturer' => 'Federal', 'name' => 'FEDERAL LINE ITEM']), $distributor);
        $this->listing($this->master([
            'manufacturer' => 'Hornady',
            'name' => 'HORNADY LINE ITEM',
            'mfr_part_number' => 'H1',
        ]), $distributor);

        $this->get(route('supply.index', ['manufacturer' => 'Hornady']))
            ->assertOk()
            ->assertSee('HORNADY LINE ITEM')
            ->assertDontSee('FEDERAL LINE ITEM');
    }

    public function test_search_matches_name_mpn_and_upc(): void
    {
        $distributor = $this->distributor();
        $this->listing($this->master([
            'name' => 'ZZZ MATCH GRADE LOAD',
            'mfr_part_number' => 'ABC-999',
            'upc' => '012345678905',
        ]), $distributor);
        $this->listing($this->master([
            'name' => 'OTHER PLINKING LOAD',
            'mfr_part_number' => 'XYZ-111',
        ]), $distributor);

        foreach (['MATCH GRADE', 'ABC-999', '012345678905'] as $term) {
            $this->get(route('supply.index', ['search' => $term]))
                ->assertOk()
                ->assertSee('ZZZ MATCH GRADE LOAD')
                ->assertDontSee('OTHER PLINKING LOAD');
        }
    }

    public function test_in_stock_only_defaults_true_and_can_be_disabled(): void
    {
        $distributor = $this->distributor();
        $this->listing(
            $this->master(['name' => 'LIVE INVENTORY ITEM']),
            $distributor,
            ['is_in_stock' => true, 'quantity_available' => 25],
        );
        $this->listing(
            $this->master(['name' => 'DEAD INVENTORY ITEM', 'mfr_part_number' => 'DEAD1']),
            $distributor,
            ['is_in_stock' => false, 'quantity_available' => 0],
        );

        $this->get(route('supply.index'))
            ->assertOk()
            ->assertSee('LIVE INVENTORY ITEM')
            ->assertDontSee('DEAD INVENTORY ITEM');

        $this->get(route('supply.index', ['in_stock_only' => '0']))
            ->assertOk()
            ->assertSee('LIVE INVENTORY ITEM')
            ->assertSee('DEAD INVENTORY ITEM');
    }

    public function test_kpis_are_calculated_from_in_stock_linked_listings(): void
    {
        $distributor = $this->distributor();

        // 9mm: cheapest in-stock per round = 9.00 / 50 = 0.18
        $nine = $this->master(['caliber' => '9mm Luger', 'rounds_per_box' => 50]);
        $this->listing($nine, $distributor, ['wholesale_price' => 12.50, 'quantity_available' => 200, 'is_in_stock' => true]);
        $this->listing($nine, $distributor, ['wholesale_price' => 9.00, 'quantity_available' => 100, 'is_in_stock' => true]);
        // a cheaper but OUT-of-stock listing must be ignored by the KPI + counts
        $this->listing($nine, $distributor, ['wholesale_price' => 1.00, 'quantity_available' => 0, 'is_in_stock' => false]);

        // 5.56: per round = 8.00 / 20 = 0.40
        $rifle = $this->master(['caliber' => '5.56x45mm NATO', 'rounds_per_box' => 20, 'mfr_part_number' => 'R1']);
        $this->listing($rifle, $distributor, ['wholesale_price' => 8.00, 'quantity_available' => 50, 'is_in_stock' => true]);

        $kpis = collect($this->get(route('supply.index'))->assertOk()->viewData('kpis'))->keyBy('title');

        $this->assertSame('2', $kpis['Tracked Master SKUs']['value']);
        $this->assertSame('3', $kpis['In-Stock Listings']['value']);
        $this->assertSame('$0.180', $kpis['Lowest 9mm $/rd']['value']);
        $this->assertSame('$0.400', $kpis['Lowest 5.56 $/rd']['value']);
        // (200 + 100) * 50  +  50 * 20  = 15,000 + 1,000
        $this->assertSame('16,000', $kpis['Rounds in Supply Chain']['value']);
    }

    public function test_lowest_price_kpi_is_a_dash_when_no_stock_exists(): void
    {
        $this->master(['caliber' => '9mm Luger']); // tracked but no listings

        $kpis = collect($this->get(route('supply.index'))->assertOk()->viewData('kpis'))->keyBy('title');

        $this->assertSame('—', $kpis['Lowest 9mm $/rd']['value']);
        $this->assertSame('0', $kpis['Rounds in Supply Chain']['value']);
    }

    public function test_filter_tabs_preserve_other_query_parameters(): void
    {
        $this->listing($this->master(), $this->distributor());

        $html = $this->get(route('supply.index', ['manufacturer' => 'Federal', 'search' => 'foo']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('manufacturer=Federal', $html);
        $this->assertStringContainsString('search=foo', $html);
        $this->assertStringContainsString('caliber=9mm+Luger', $html);
    }

    public function test_results_are_paginated_at_25_per_page(): void
    {
        $distributor = $this->distributor();

        foreach (range(1, 30) as $i) {
            $this->listing(
                $this->master(['name' => sprintf('PAGINATED ITEM %03d', $i), 'mfr_part_number' => "P{$i}"]),
                $distributor,
            );
        }

        $page1 = $this->get(route('supply.index'))->assertOk();
        $this->assertCount(25, $page1->viewData('masters')->items());

        $page2 = $this->get(route('supply.index', ['page' => 2]))->assertOk();
        $this->assertCount(5, $page2->viewData('masters')->items());
    }

    public function test_distributors_health_view_reports_status_and_counts(): void
    {
        $active = $this->distributor([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'transport_type' => 'sftp',
            'is_active' => true,
            'last_synced_at' => now()->subMinutes(10),
        ]);
        $this->distributor([
            'name' => 'Zzz Wholesale',
            'slug' => 'zzz',
            'transport_type' => 'ftp',
            'is_active' => false,
        ]);

        $this->listing($this->master(), $active);
        $this->listing($this->master(['mfr_part_number' => 'A2']), $active);
        $this->listing($this->master(['mfr_part_number' => 'A3']), $active);

        FeedRun::create([
            'distributor_id' => $active->id,
            'status' => 'completed',
            'started_at' => now()->subMinutes(9),
            'finished_at' => now()->subMinutes(8),
            'rows_processed' => 10,
        ]);
        FeedRun::create([
            'distributor_id' => $active->id,
            'status' => 'failed',
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(2),
            'error_message' => 'boom',
        ]);

        // The Distributors & Feed Health console is limited to admins.
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('supply.distributors'))
            ->assertOk();
        $response->assertSeeInOrder(['RSR Group', 'sftp', 'failed']);
        $response->assertSee('Zzz Wholesale');
        $response->assertSee('Products Tracked');

        $rows = collect($response->viewData('distributors'));

        $rsr = $rows->firstWhere('slug', 'rsr');
        $this->assertSame('sftp', $rsr['transport_type']);
        $this->assertTrue($rsr['is_active']);
        $this->assertSame('failed', $rsr['latest_status']);
        $this->assertSame(3, $rsr['products_tracked']);

        $zzz = $rows->firstWhere('slug', 'zzz');
        $this->assertFalse($zzz['is_active']);
        $this->assertNull($zzz['latest_status']);
        $this->assertSame(0, $zzz['products_tracked']);
    }

    public function test_flash_messages_render_in_the_layout(): void
    {
        $this->listing($this->master(), $this->distributor());

        $this->withSession(['success' => 'Feed synced successfully.'])
            ->get(route('supply.index'))
            ->assertOk()
            ->assertSee('Feed synced successfully.');
    }
}
