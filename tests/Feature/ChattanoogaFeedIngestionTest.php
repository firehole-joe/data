<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\DistributorSkuOverride;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\AmmoAttributeExtractor;
use App\Services\Ammunition\SupplyReportQueryService;
use App\Services\Feeds\Drivers\ChattanoogaFeedDriver;
use App\Services\Feeds\FeedIngestionService;
use Database\Seeders\DistributorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ChattanoogaFeedIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/chattanooga_itemInventory_sample.csv';

    protected function setUp(): void
    {
        parent::setUp();

        // Swap only the two-legged REST retrieval; the real CSV parser,
        // ammo filter and pricing pipeline still run against the fixture.
        $mock = Mockery::mock(
            ChattanoogaFeedDriver::class.'[downloadFeed]',
            [new AmmoAttributeExtractor],
        );
        $mock->shouldReceive('downloadFeed')->andReturnUsing(function (): string {
            $tmp = tempnam(sys_get_temp_dir(), 'test_chattanooga_');
            copy(self::FIXTURE, $tmp);

            return $tmp;
        });

        $this->app->instance(ChattanoogaFeedDriver::class, $mock);
    }

    private function chattanooga(array $overrides = []): Distributor
    {
        return Distributor::create(array_merge([
            'name' => 'Chattanooga Shooting Supplies',
            'slug' => 'chattanooga',
            'driver_class' => ChattanoogaFeedDriver::class,
            'transport_type' => 'rest_api',
            'connection_settings' => [
                'base_uri' => 'https://api.chattanoogashooting.com/rest/v6/',
                'sid' => 'FIREHOLE',
                'token' => 'super-secret-token',
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

    public function test_it_keeps_ammunition_rows_and_drops_everything_else(): void
    {
        $chattanooga = $this->chattanooga();

        $this->ingest($chattanooga);

        $skus = DistributorProduct::where('distributor_id', $chattanooga->id)
            ->orderBy('distributor_sku')
            ->pluck('distributor_sku')
            ->all();

        $this->assertSame(
            ['CSSI-AE9', 'CSSI-CCI22', 'CSSI-CCI9MM', 'CSSI-PMC45', 'CSSI-XM855'],
            $skus,
        );

        // Firearm (explicit non-ammo category), optic and gun case
        // (blank category, no caliber) are all rejected.
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'CSSI-GLK19']);
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'CSSI-VTX-PST']);
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'CSSI-PLANO42']);
    }

    public function test_blank_category_rows_are_kept_when_a_caliber_is_detected(): void
    {
        $chattanooga = $this->chattanooga();

        $this->ingest($chattanooga);

        // CSSI-PMC45 has an empty Category; it survives only because the
        // description resolves to .45 ACP.
        $offering = DistributorProduct::where('distributor_sku', 'CSSI-PMC45')->firstOrFail();

        $this->assertSame('741569070256', $offering->raw_upc);
        $this->assertSame(0, $offering->quantity_available);
        $this->assertFalse((bool) $offering->is_in_stock);
    }

    public function test_it_maps_the_iteminventory_columns(): void
    {
        $chattanooga = $this->chattanooga();

        $this->ingest($chattanooga);

        $offering = DistributorProduct::where('distributor_sku', 'CSSI-XM855')->firstOrFail();

        $this->assertSame('020892221994', $offering->raw_upc, 'UPC Code -> raw_upc');
        $this->assertSame('XM855', $offering->raw_mfr_part_number, 'Manufacturer Item Number -> mpn');
        $this->assertSame(
            'FEDERAL AMERICAN EAGLE 5.56 XM855 62GR FMJ 20RD',
            $offering->raw_description,
            'Item Description -> raw_description',
        );
        $this->assertEqualsWithDelta(7.95, (float) $offering->wholesale_price, 0.001, 'Price -> wholesale_price');
        $this->assertSame(410, $offering->quantity_available, 'Qty On Hand -> quantity_available');
        $this->assertTrue((bool) $offering->is_in_stock);
    }

    public function test_dirty_upc_is_normalised_with_leading_zeros_preserved(): void
    {
        $chattanooga = $this->chattanooga();

        $this->ingest($chattanooga);

        $offering = DistributorProduct::where('distributor_sku', 'CSSI-CCI9MM')->firstOrFail();

        // Source cell is "0 76683-03528 8".
        $this->assertSame('076683035288', $offering->raw_upc);
    }

    public function test_guardrail_flags_an_out_of_band_offering(): void
    {
        $chattanooga = $this->chattanooga();

        $this->ingest($chattanooga);

        // .22 LR at $39.99 / 50rd = $0.80/rd — over the rimfire ceiling.
        $offering = DistributorProduct::where('distributor_sku', 'CSSI-CCI22')->firstOrFail();

        $this->assertTrue((bool) $offering->needs_review);
        $this->assertStringContainsString('rimfire ceiling', (string) $offering->review_reason);
    }

    public function test_a_durable_ignore_override_keeps_an_offering_out_of_the_queue(): void
    {
        $chattanooga = $this->chattanooga();

        DistributorSkuOverride::create([
            'distributor_id' => $chattanooga->id,
            'distributor_sku' => 'CSSI-CCI22',
            'upc' => '076683000361',
            'is_ignored' => true,
            'round_count' => 0,
            'baseline_price' => 39.99,
            'baseline_description' => 'CCI MINI-MAG 22LR 40GR CPRN 50RD',
        ]);

        $this->ingest($chattanooga);

        $offering = DistributorProduct::where('distributor_sku', 'CSSI-CCI22')->firstOrFail();

        $this->assertTrue((bool) $offering->is_ignored);
        $this->assertFalse((bool) $offering->needs_review);
    }

    public function test_shared_upc_puts_chattanooga_alongside_rsr_on_one_master_row(): void
    {
        $rsr = Distributor::create([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'driver_class' => 'App\\Services\\Feeds\\Drivers\\RsrFeedDriver',
            'transport_type' => 'ftp',
            'connection_settings' => [],
        ]);
        $chattanooga = $this->chattanooga();

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

        $this->ingest($chattanooga);

        $offering = DistributorProduct::where('distributor_sku', 'CSSI-CCI9MM')->firstOrFail();
        $this->assertSame($master->id, $offering->master_ammunition_id, 'linked to the shared master by UPC');

        $row = app(SupplyReportQueryService::class)
            ->paginate($this->filters(['per_page' => 100]))
            ->firstWhere('id', $master->id);

        $this->assertNotNull($row, 'the shared master appears on the dashboard');
        $this->assertSame(2, $row->listing_count);
        $this->assertEqualsCanonicalizing(
            ['Chattanooga Shooting Supplies', 'RSR Group'],
            $row->distributor_badges->pluck('name')->all(),
        );
        $this->assertSame(9.05, $row->best_price_per_box, 'Chattanooga is the cheaper listing');
        $this->assertEqualsCanonicalizing(
            ['CSSI-CCI9MM', 'RSR-CCI9MM'],
            collect($row->offerings)->pluck('sku')->all(),
        );
    }

    public function test_dashboard_surfaces_chattanooga_as_a_distributor_facet_and_in_scope(): void
    {
        $chattanooga = $this->chattanooga();
        $this->ingest($chattanooga);

        $response = $this->get(route('supply.dashboard'))
            ->assertOk()
            ->assertSee('Chattanooga Shooting Supplies');

        $facetNames = collect($response->viewData('facets')['distributors'])->pluck('name');
        $this->assertTrue($facetNames->contains('Chattanooga Shooting Supplies'), 'appears as a filter chip');

        $stats = app(SupplyReportQueryService::class)->stats(
            $this->filters(['distributors' => [$chattanooga->id]])
        );
        $this->assertSame('Chattanooga Shooting Supplies', $stats['scope_label']);
        $this->assertGreaterThan(0, $stats['offer_count']);
    }

    public function test_feed_sync_command_runs_the_chattanooga_feed(): void
    {
        $this->chattanooga();

        $this->artisan('feed:sync', ['slug' => 'chattanooga', '--force' => true])->assertExitCode(0);

        $this->assertSame(5, DistributorProduct::where('distributor_sku', 'like', 'CSSI-%')->count());
        $this->assertDatabaseHas('feed_runs', ['status' => 'completed']);
    }

    public function test_feed_sync_command_dry_run_persists_nothing_for_chattanooga(): void
    {
        $this->chattanooga();

        $this->artisan('feed:sync', ['slug' => 'chattanooga', '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, DistributorProduct::count());
    }

    public function test_seeder_registers_chattanooga_as_a_rest_api_distributor(): void
    {
        $this->seed(DistributorSeeder::class);

        $chattanooga = Distributor::where('slug', 'chattanooga')->firstOrFail();

        $this->assertSame('rest_api', $chattanooga->transport_type);
        $this->assertSame(ChattanoogaFeedDriver::class, $chattanooga->driver_class);

        $settings = $chattanooga->connection_settings;
        $this->assertSame('https://api.chattanoogashooting.com/rest/v6/', $settings['base_uri']);
        $this->assertArrayHasKey('sid', $settings);
        $this->assertArrayHasKey('token', $settings);
    }

    public function test_download_resolves_the_product_feed_url_with_basic_sid_md5_token_auth(): void
    {
        // Drop the setUp partial mock and exercise the real two-legged download.
        $this->app->forgetInstance(ChattanoogaFeedDriver::class);

        $csv = (string) file_get_contents(self::FIXTURE);

        Http::fake([
            'api.chattanoogashooting.com/rest/v6/items/product-feed' => Http::response([
                'product_feed' => [
                    'url' => 'https://feeds.chattanoogashooting.com/exports/itemInventory.csv?key=abc123',
                ],
            ]),
            'feeds.chattanoogashooting.com/*' => Http::response($csv, 200, [
                'Content-Type' => 'text/csv',
            ]),
        ]);

        $chattanooga = $this->chattanooga();
        $path = app(ChattanoogaFeedDriver::class)->downloadFeed($chattanooga);

        $this->assertFileExists($path);
        $this->assertSame($csv, (string) file_get_contents($path));
        @unlink($path);

        $expected = 'Basic '.base64_encode('FIREHOLE:'.md5('super-secret-token'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/items/product-feed')
            && $request->header('Authorization')[0] === $expected);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'feeds.chattanoogashooting.com')
            && $request->header('Authorization')[0] === $expected);
    }
}
