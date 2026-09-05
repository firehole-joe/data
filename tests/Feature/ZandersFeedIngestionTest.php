<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\DistributorSkuOverride;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\SupplyReportQueryService;
use App\Services\Feeds\Drivers\ZandersFeedDriver;
use App\Services\Feeds\FeedIngestionService;
use Database\Seeders\DistributorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ZandersFeedIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/zandersinv_sample.csv';

    protected function setUp(): void
    {
        parent::setUp();

        // Replace only the FTP transport; the real CSV parser still runs.
        $mock = Mockery::mock(ZandersFeedDriver::class.'[downloadFeed]');
        $mock->shouldReceive('downloadFeed')->andReturnUsing(function (): string {
            $tmp = tempnam(sys_get_temp_dir(), 'test_zanders_');
            copy(self::FIXTURE, $tmp);

            return $tmp;
        });

        $this->app->instance(ZandersFeedDriver::class, $mock);
    }

    private function zanders(array $overrides = []): Distributor
    {
        return Distributor::create(array_merge([
            'name' => 'Zanders Sporting Goods',
            'slug' => 'zanders',
            'driver_class' => ZandersFeedDriver::class,
            'transport_type' => 'ftp',
            'connection_settings' => [
                'host' => 'ftp2.gzanders.com',
                'port' => 21,
                'username' => 'DarkHills',
                'password' => 'secret',
                'remote_path' => 'Inventory/zandersinv.csv',
                'passive' => true,
            ],
        ], $overrides));
    }

    private function ingest(Distributor $distributor, bool $dryRun = false): void
    {
        app(FeedIngestionService::class)->ingest($distributor, $dryRun);
    }

    private function filters(array $input = []): array
    {
        return app(SupplyReportQueryService::class)->normalizeFilters(new Request($input));
    }

    /* -------------------------------------------------------------- */

    public function test_it_ingests_only_ammunition_category_rows(): void
    {
        $zanders = $this->zanders();

        $this->ingest($zanders);

        $skus = DistributorProduct::where('distributor_id', $zanders->id)
            ->orderBy('distributor_sku')
            ->pluck('distributor_sku')
            ->all();

        $this->assertSame(
            ['ZAN-CCI22', 'ZAN-CCI9MM', 'ZAN-FED556', 'ZAN-MT308', 'ZAN-PMC45', 'ZAN-PPU9', 'ZAN-RECLAIM9'],
            $skus,
        );
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'ZAN-GLK19']);
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'ZAN-LEUP']);
    }

    public function test_it_maps_the_zandersinv_columns_with_a_joined_description(): void
    {
        $zanders = $this->zanders();

        $this->ingest($zanders);

        $offering = DistributorProduct::where('distributor_sku', 'ZAN-CCI9MM')->firstOrFail();

        $this->assertSame('076683035288', $offering->raw_upc, 'dirty UPC is normalised');
        $this->assertSame('5200', $offering->raw_mfr_part_number, 'MFGPNum -> manufacturer_part_number');
        $this->assertSame('CCI Blazer Brass 9mm Luger 115gr FMJ 50rd', $offering->raw_description, 'Desc1 + Desc2');
        $this->assertEqualsWithDelta(9.10, (float) $offering->wholesale_price, 0.001, 'Price1 -> wholesale');
        $this->assertSame(300, $offering->quantity_available, 'Avail -> quantity');
        $this->assertTrue((bool) $offering->is_in_stock);
    }

    public function test_it_stores_the_feed_manufacturer_column_on_the_offering(): void
    {
        $zanders = $this->zanders();

        $this->ingest($zanders);

        $this->assertSame(
            'Federal',
            DistributorProduct::where('distributor_sku', 'ZAN-FED556')->value('raw_manufacturer'),
        );
        $this->assertSame(
            'PPU',
            DistributorProduct::where('distributor_sku', 'ZAN-PPU9')->value('raw_manufacturer'),
        );
    }

    public function test_the_mfg_column_brands_a_master_whose_description_names_no_brand(): void
    {
        $zanders = $this->zanders();

        $this->ingest($zanders);

        // "Tac-Load 9mm Luger 124gr FMJ 50rd" carries no recognisable
        // brand — only the feed's MFG column ("PPU") does.
        $offering = DistributorProduct::where('distributor_sku', 'ZAN-PPU9')->firstOrFail();

        $this->assertNotNull($offering->master_ammunition_id);
        $this->assertSame('Prvi Partizan', $offering->masterAmmunition->manufacturer);
        $this->assertNotSame('Unknown', $offering->masterAmmunition->manufacturer);
    }

    public function test_zero_avail_is_recorded_as_out_of_stock(): void
    {
        $zanders = $this->zanders();

        $this->ingest($zanders);

        $offering = DistributorProduct::where('distributor_sku', 'ZAN-PMC45')->firstOrFail();

        $this->assertSame(0, $offering->quantity_available);
        $this->assertFalse((bool) $offering->is_in_stock);
    }

    public function test_shared_upc_puts_zanders_alongside_rsr_on_one_master_row(): void
    {
        $rsr = Distributor::create([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'driver_class' => 'App\\Services\\Feeds\\Drivers\\RsrFeedDriver',
            'transport_type' => 'ftp',
            'connection_settings' => [],
        ]);
        $zanders = $this->zanders();

        $master = MasterAmmunition::create([
            'upc' => '076683035288',
            'manufacturer' => 'CCI',
            'mfr_part_number' => '5200',
            'name' => 'CCI Blazer Brass 9mm 115gr FMJ',
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 115,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 50,
            'is_tracked_in_report' => true,
        ]);

        DistributorProduct::create([
            'distributor_id' => $rsr->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => 'RSR-CCI9MM',
            'raw_upc' => '076683035288',
            'raw_description' => 'CCI BLAZER BRASS 9MM 115GR FMJ 50RD',
            'wholesale_price' => 9.45,
            'quantity_available' => 120,
            'is_in_stock' => true,
        ]);

        $this->ingest($zanders);

        $zandersOffering = DistributorProduct::where('distributor_sku', 'ZAN-CCI9MM')->firstOrFail();
        $this->assertSame($master->id, $zandersOffering->master_ammunition_id, 'linked to the shared master by UPC');

        $row = app(SupplyReportQueryService::class)
            ->paginate($this->filters(['per_page' => 100]))
            ->firstWhere('id', $master->id);

        $this->assertNotNull($row, 'the shared master appears on the dashboard');
        $this->assertSame(2, $row->listing_count);
        $this->assertEqualsCanonicalizing(
            ['RSR Group', 'Zanders Sporting Goods'],
            $row->distributor_badges->pluck('name')->all(),
        );
        $this->assertSame(9.10, $row->best_price_per_box, 'Zanders is the cheaper listing');
        $this->assertEqualsCanonicalizing(
            ['RSR-CCI9MM', 'ZAN-CCI9MM'],
            collect($row->offerings)->pluck('sku')->all(),
        );
    }

    public function test_guardrail_flags_an_out_of_band_zanders_offering(): void
    {
        $zanders = $this->zanders();

        $this->ingest($zanders);

        // .22 LR at $45.00 / 50rd = $0.90/rd — over the rimfire ceiling.
        $offering = DistributorProduct::where('distributor_sku', 'ZAN-CCI22')->firstOrFail();

        $this->assertTrue((bool) $offering->needs_review);
        $this->assertStringContainsString('rimfire ceiling', (string) $offering->review_reason);
    }

    public function test_a_durable_ignore_override_keeps_a_zanders_offering_out_of_the_queue(): void
    {
        $zanders = $this->zanders();

        DistributorSkuOverride::create([
            'distributor_id' => $zanders->id,
            'distributor_sku' => 'ZAN-RECLAIM9',
            'upc' => '111111111119',
            'is_ignored' => true,
            'round_count' => 0,
            'baseline_price' => 3.00,
            'baseline_description' => 'Reclaimed 9mm Range Brass Loaded 50rd',
        ]);

        $this->ingest($zanders);

        $offering = DistributorProduct::where('distributor_sku', 'ZAN-RECLAIM9')->firstOrFail();

        // $3.00 / 50rd = $0.06/rd would normally flag below the handgun floor.
        $this->assertTrue((bool) $offering->is_ignored);
        $this->assertFalse((bool) $offering->needs_review);
    }

    public function test_an_approved_round_count_override_is_applied_to_a_zanders_offering(): void
    {
        $zanders = $this->zanders();

        DistributorSkuOverride::create([
            'distributor_id' => $zanders->id,
            'distributor_sku' => 'ZAN-MT308',
            'upc' => '754908162307',
            'round_count' => 1000,
            'is_ignored' => false,
            'baseline_price' => 540.00,
            'baseline_description' => 'Magtech 308 Win M80 147gr FMJ 50/1000',
        ]);

        $this->ingest($zanders);

        $offering = DistributorProduct::where('distributor_sku', 'ZAN-MT308')->firstOrFail();

        // $540 / 1000rd = $0.54/rd — inside the rifle band once the
        // reviewer-confirmed case count is applied.
        $this->assertSame(1000, $offering->round_count);
        $this->assertFalse((bool) $offering->needs_review);
        $this->assertEqualsWithDelta(0.54, (float) $offering->cost_per_round, 0.0001);
    }

    public function test_dashboard_surfaces_zanders_as_a_distributor_facet_and_in_scope(): void
    {
        $zanders = $this->zanders();
        $this->ingest($zanders);

        $response = $this->get(route('supply.dashboard'))->assertOk()->assertSee('Zanders Sporting Goods');

        $facetNames = collect($response->viewData('facets')['distributors'])->pluck('name');
        $this->assertTrue($facetNames->contains('Zanders Sporting Goods'), 'appears as a filter chip');

        $stats = app(SupplyReportQueryService::class)->stats(
            $this->filters(['distributors' => [$zanders->id]])
        );
        $this->assertSame('Zanders Sporting Goods', $stats['scope_label']);
        $this->assertGreaterThan(0, $stats['offer_count']);
    }

    public function test_feed_sync_command_runs_the_zanders_feed(): void
    {
        $this->zanders();

        $this->artisan('feed:sync', ['slug' => 'zanders'])->assertExitCode(0);

        $this->assertSame(7, DistributorProduct::where('distributor_sku', 'like', 'ZAN-%')->count());
        $this->assertDatabaseHas('feed_runs', ['status' => 'completed']);
    }

    public function test_feed_sync_command_dry_run_persists_nothing_for_zanders(): void
    {
        $this->zanders();

        $this->artisan('feed:sync', ['slug' => 'zanders', '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, DistributorProduct::count());
    }

    public function test_seeder_wires_zanders_ftp_connection_from_config(): void
    {
        $this->seed(DistributorSeeder::class);

        $zanders = Distributor::where('slug', 'zanders')->firstOrFail();

        $this->assertSame('ftp', $zanders->transport_type);
        $this->assertSame(ZandersFeedDriver::class, $zanders->driver_class);

        $settings = $zanders->connection_settings;
        $this->assertSame('ftp2.gzanders.com', $settings['host']);
        $this->assertSame(21, (int) $settings['port']);
        $this->assertSame('Inventory/zandersinv.csv', $settings['remote_path']);
    }
}
