<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Models\User;
use App\Services\Ammunition\SupplyReportQueryService;
use App\Services\Feeds\Drivers\DavidsonsFeedDriver;
use App\Services\Feeds\FeedIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class DavidsonsFeedDriverTest extends TestCase
{
    use RefreshDatabase;

    private const ITEMSPEC = __DIR__.'/../Fixtures/davidsons_v2_itemspec_sample.csv';

    private const QTY = __DIR__.'/../Fixtures/davidsons_v2_qty_sample.csv';

    protected function setUp(): void
    {
        parent::setUp();

        // Replace only the SFTP transport; the real two-file merge and
        // the real CSV parser both still run against the fixtures.
        $mock = Mockery::mock(DavidsonsFeedDriver::class.'[downloadFeed]');
        $mock->shouldReceive('downloadFeed')->andReturnUsing(
            fn () => (new DavidsonsFeedDriver)->mergeItemspecWithQuantities(self::ITEMSPEC, self::QTY)
        );

        $this->app->instance(DavidsonsFeedDriver::class, $mock);
    }

    private function distributor(array $overrides = []): Distributor
    {
        return Distributor::create(array_merge([
            'name' => "Davidson's",
            'slug' => 'davidsons',
            'driver_class' => DavidsonsFeedDriver::class,
            'transport_type' => 'sftp',
            'connection_settings' => [
                'host' => 'ftp.davidsons.com',
                'port' => 22,
                'username' => 'firehole',
                'password' => 'secret',
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

    /* ------------------------------------------------------------------ */

    public function test_it_ingests_only_ammunition_rows(): void
    {
        $davidsons = $this->distributor();

        $this->ingest($davidsons);

        $skus = DistributorProduct::where('distributor_id', $davidsons->id)
            ->orderBy('distributor_sku')
            ->pluck('distributor_sku')
            ->all();

        $this->assertSame(
            ['DAV-EDGE9', 'DAV-FED9', 'DAV-FED9-CASE', 'DAV-HOR65', 'DAV-NOQTY', 'DAV-PMC223', 'DAV-WIN12'],
            $skus,
        );
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'DAV-SPR-HC']);
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'DAV-AERO-LWR']);
    }

    public function test_it_maps_pricing_quantity_and_round_count(): void
    {
        $davidsons = $this->distributor();
        $this->ingest($davidsons);

        $box = DistributorProduct::where('distributor_sku', 'DAV-PMC223')->firstOrFail();
        $this->assertSame('741569070430', $box->raw_upc);
        $this->assertEqualsWithDelta(6.85, (float) $box->wholesale_price, 0.001);
        $this->assertSame(600, $box->quantity_available);
        $this->assertTrue((bool) $box->is_in_stock);
        $this->assertSame(20, $box->round_count, 'round_per_box is pinned directly to the offering');

        $onSale = DistributorProduct::where('distributor_sku', 'DAV-FED9')->firstOrFail();
        $this->assertEqualsWithDelta(7.99, (float) $onSale->wholesale_price, 0.001, 'SalePrice undercuts DealerPrice');
        $this->assertSame(0, $onSale->quantity_available, 'merged from V2_Qty.csv by ItemNo');
        $this->assertFalse((bool) $onSale->is_in_stock);

        $noQty = DistributorProduct::where('distributor_sku', 'DAV-NOQTY')->firstOrFail();
        $this->assertSame(0, $noQty->quantity_available, 'absent from V2_Qty.csv defaults to 0, not dropped');
    }

    public function test_a_case_sku_does_not_poison_the_shared_box_sku(): void
    {
        $davidsons = $this->distributor();
        $this->ingest($davidsons);

        $box = DistributorProduct::where('distributor_sku', 'DAV-FED9')->firstOrFail();
        $case = DistributorProduct::where('distributor_sku', 'DAV-FED9-CASE')->firstOrFail();

        $this->assertSame($box->master_ammunition_id, $case->master_ammunition_id, 'shared UPC, one master');
        $this->assertSame(50, $box->round_count);
        $this->assertSame(1000, $case->round_count);

        // The box's own cost-per-round is healthy — never divided by the
        // case's 1000-round count.
        $this->assertEqualsWithDelta(0.1598, (float) $box->cost_per_round, 0.0005);
        $this->assertFalse((bool) $box->needs_review);
        $this->assertFalse((bool) $case->needs_review);

        // The shared master was not left pinned to the case count.
        $master = MasterAmmunition::find($box->master_ammunition_id);
        $this->assertLessThan(200, (int) $master->rounds_per_box);
    }

    public function test_dry_run_persists_nothing(): void
    {
        $this->distributor();

        $this->artisan('feed:sync', ['slug' => 'davidsons', '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, DistributorProduct::count());
    }

    public function test_feed_sync_command_runs_the_davidsons_feed(): void
    {
        $this->distributor();

        $this->artisan('feed:sync', ['slug' => 'davidsons', '--force' => true])->assertExitCode(0);

        $this->assertSame(7, DistributorProduct::where('distributor_sku', 'like', 'DAV-%')->count());
        $this->assertDatabaseHas('feed_runs', ['status' => 'completed']);
    }

    public function test_davidsons_appears_on_the_distributors_and_feed_health_dashboard(): void
    {
        $davidsons = $this->distributor();
        $this->ingest($davidsons);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('supply.distributors'))
            ->assertOk()
            ->assertSee('Davidson&#039;s', false);
    }

    public function test_davidsons_appears_as_a_dashboard_distributor_facet(): void
    {
        $davidsons = $this->distributor();
        $this->ingest($davidsons);

        $response = $this->get(route('supply.dashboard'))->assertOk();

        $facetNames = collect($response->viewData('facets')['distributors'])->pluck('name');
        $this->assertTrue($facetNames->contains("Davidson's"));

        $stats = app(SupplyReportQueryService::class)->stats(
            $this->filters(['distributors' => [$davidsons->id]])
        );
        $this->assertSame("Davidson's", $stats['scope_label']);
        $this->assertGreaterThan(0, $stats['offer_count']);
    }

    public function test_seeder_registers_davidsons_as_an_sftp_distributor(): void
    {
        $this->seed(\Database\Seeders\DistributorSeeder::class);

        $davidsons = Distributor::where('slug', 'davidsons')->firstOrFail();

        $this->assertSame("Davidson's", $davidsons->name);
        $this->assertSame('sftp', $davidsons->transport_type);
        $this->assertSame(DavidsonsFeedDriver::class, $davidsons->driver_class);
        $this->assertTrue((bool) $davidsons->is_active);

        $settings = $davidsons->connection_settings;
        $this->assertSame('ftp.davidsons.com', $settings['host']);
        $this->assertSame(22, (int) $settings['port']);
        $this->assertSame('V2_Itemspec.csv', $settings['itemspec_path']);
        $this->assertSame('V2_Qty.csv', $settings['qty_path']);
    }

    /* ------------------------------------------------------------------ */
    /*  connection_settings falls back to config when null / incomplete */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function resolvedSettings(Distributor $distributor): array
    {
        $method = new \ReflectionMethod(DavidsonsFeedDriver::class, 'settingsFor');
        $method->setAccessible(true);

        return $method->invoke(new DavidsonsFeedDriver, $distributor);
    }

    public function test_an_empty_connection_settings_row_falls_back_entirely_to_config(): void
    {
        config(['distributors.davidsons' => [
            'transport' => 'sftp',
            'host' => 'ftp.davidsons.com',
            'port' => 22,
            'username' => 'configured-user',
            'password' => 'configured-pass',
            'itemspec_path' => 'V2_Itemspec.csv',
            'qty_path' => 'V2_Qty.csv',
        ]]);

        // `connection_settings` is a NOT NULL column — an "empty" row in
        // practice is `[]` (or, per Distributor::connection_settings ??
        // [] throughout the codebase, whatever a genuinely-null decrypted
        // value would coalesce to), not a literal SQL NULL.
        $davidsons = $this->distributor(['connection_settings' => []]);

        $settings = $this->resolvedSettings($davidsons);

        $this->assertSame('ftp.davidsons.com', $settings['host']);
        $this->assertSame(22, $settings['port']);
        $this->assertSame('configured-user', $settings['username']);
        $this->assertSame('configured-pass', $settings['password']);
        $this->assertSame('V2_Itemspec.csv', $settings['itemspec_path']);
        $this->assertSame('V2_Qty.csv', $settings['qty_path']);
    }

    public function test_blank_fields_in_a_stored_row_are_backfilled_from_config_without_losing_the_rest(): void
    {
        config(['distributors.davidsons' => [
            'host' => 'ftp.davidsons.com',
            'port' => 22,
            'username' => 'configured-user',
            'password' => 'configured-pass',
        ]]);

        // The row has an explicit host but blank/missing credentials —
        // e.g. an admin set the host through the UI before the
        // environment's username/password existed.
        $davidsons = $this->distributor(['connection_settings' => [
            'host' => 'override.davidsons.example',
            'username' => '',
            'password' => null,
        ]]);

        $settings = $this->resolvedSettings($davidsons);

        $this->assertSame('override.davidsons.example', $settings['host'], "the row's own value always wins");
        $this->assertSame('configured-user', $settings['username'], 'blank stored value falls back to config');
        $this->assertSame('configured-pass', $settings['password'], 'null stored value falls back to config');
    }

    public function test_settings_are_untouched_when_there_is_no_config_block_for_the_slug(): void
    {
        config(['distributors.davidsons' => null]);

        $davidsons = $this->distributor(['connection_settings' => ['host' => 'only.the.row.example']]);

        $this->assertSame(['host' => 'only.the.row.example'], $this->resolvedSettings($davidsons));
    }
}
