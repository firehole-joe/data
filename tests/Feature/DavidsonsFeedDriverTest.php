<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\DistributorSkuOverride;
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
            ['DAV-EDGE9', 'DAV-FED9', 'DAV-FED9-CASE', 'DAV-HOR65', 'DAV-NOQTY', 'DAV-PMC223', 'DAV-PMC9BX', 'DAV-WIN12'],
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

    public function test_box_per_case_does_not_inflate_a_plain_retail_boxs_round_count(): void
    {
        // Reported bug: DealerPrice $13.50 for a single 50-round box with
        // box_per_case=10 was getting stamped round_count=500 (50 * 10),
        // pricing at ~$0.027/rd and getting flagged as a bogus case-count
        // parse. box_per_case is carton packaging metadata only; with no
        // "Case"/"CS" marker and a price nowhere near a case threshold,
        // the box's own round_per_box (50) must be trusted as-is.
        $davidsons = $this->distributor();
        $this->ingest($davidsons);

        $offering = DistributorProduct::where('distributor_sku', 'DAV-PMC9BX')->firstOrFail();

        $this->assertSame(50, $offering->round_count, 'NOT 500 (50 * box_per_case)');
        $this->assertEqualsWithDelta(13.50, (float) $offering->wholesale_price, 0.001);
        $this->assertEqualsWithDelta(0.27, (float) $offering->cost_per_round, 0.001);
        $this->assertFalse((bool) $offering->needs_review);
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

        $this->assertSame(8, DistributorProduct::where('distributor_sku', 'like', 'DAV-%')->count());
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

    /* ------------------------------------------------------------------ */
    /*  davidsons:repair-round-counts */
    /* ------------------------------------------------------------------ */

    private function poisonedOffering(Distributor $distributor, array $overrides = [], array $masterOverrides = []): DistributorProduct
    {
        static $n = 0;
        $n++;

        $master = MasterAmmunition::create(array_merge([
            'manufacturer' => 'PMC',
            'mfr_part_number' => "REPAIR-{$n}",
            'name' => "Repair Master {$n}",
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 115,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 50,
            'is_tracked_in_report' => true,
        ], $masterOverrides));

        return DistributorProduct::create(array_merge([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => "DAV-REPAIR-{$n}",
            'raw_description' => 'PMC BRONZE 9MM 115GR FMJ 50RD',
            'wholesale_price' => 13.50,
            'cost_per_round' => 0.027,
            'round_count' => 500,
            'quantity_available' => 40,
            'is_in_stock' => true,
            'needs_review' => true,
            'review_reason' => '$0.0270/rd is below the $0.05 centerfire floor',
        ], $overrides));
    }

    public function test_repair_command_restores_the_true_round_count_and_clears_the_flag(): void
    {
        $davidsons = $this->distributor();
        // raw_description states "50RD" in plain text — the same signal
        // AmmoAttributeExtractor already uses everywhere else — even
        // though the driver itself used the structured round_per_box
        // column, which is what got multiplied into the stored 500.
        $offering = $this->poisonedOffering($davidsons);

        $this->artisan('davidsons:repair-round-counts')->assertExitCode(0);

        $fresh = $offering->fresh();
        $this->assertSame(50, $fresh->round_count);
        $this->assertEqualsWithDelta(0.27, (float) $fresh->cost_per_round, 0.001);
        $this->assertFalse((bool) $fresh->needs_review);
        $this->assertNull($fresh->review_reason);
    }

    public function test_repair_command_falls_back_to_the_master_when_the_description_has_no_explicit_count(): void
    {
        $davidsons = $this->distributor();
        $offering = $this->poisonedOffering(
            $davidsons,
            ['raw_description' => 'PMC BRONZE 9MM RANGE PACK'],
            ['rounds_per_box' => 50],
        );

        $this->artisan('davidsons:repair-round-counts')->assertExitCode(0);

        $fresh = $offering->fresh();
        $this->assertSame(50, $fresh->round_count);
        $this->assertFalse((bool) $fresh->needs_review);
    }

    public function test_repair_command_dry_run_persists_nothing(): void
    {
        $davidsons = $this->distributor();
        $offering = $this->poisonedOffering($davidsons);

        $this->artisan('davidsons:repair-round-counts', ['--dry-run' => true])->assertExitCode(0);

        $fresh = $offering->fresh();
        $this->assertSame(500, $fresh->round_count);
        $this->assertTrue((bool) $fresh->needs_review);
    }

    public function test_repair_command_never_overturns_a_reviewers_ledgered_decision(): void
    {
        $davidsons = $this->distributor();
        $offering = $this->poisonedOffering($davidsons, ['distributor_sku' => 'DAV-REVIEWED']);

        // A human already looked at this exact SKU and confirmed 500 is
        // correct (an unusual bulk pack, say) — the repair must not
        // second-guess that.
        DistributorSkuOverride::create([
            'distributor_id' => $davidsons->id,
            'distributor_sku' => 'DAV-REVIEWED',
            'round_count' => 500,
            'is_ignored' => false,
        ]);

        $this->artisan('davidsons:repair-round-counts')->assertExitCode(0);

        $fresh = $offering->fresh();
        $this->assertSame(500, $fresh->round_count, 'the ledgered decision is left untouched');
    }

    public function test_repair_command_skips_a_row_with_no_smaller_count_available(): void
    {
        $davidsons = $this->distributor();

        // No explicit count in the description, and the master itself is
        // case-sized — nothing reliable to repair from.
        $noSignal = $this->poisonedOffering(
            $davidsons,
            ['distributor_sku' => 'DAV-NOSIGNAL', 'raw_description' => 'PMC BRONZE 9MM RANGE PACK'],
            ['rounds_per_box' => 1000],
        );

        // The description's own explicit count (50) is not smaller than
        // what is already stored — nothing looks inflated.
        $alreadyCorrect = $this->poisonedOffering($davidsons, [
            'distributor_sku' => 'DAV-ALREADYCORRECT',
            'round_count' => 50,
            'needs_review' => true,
        ]);

        $this->artisan('davidsons:repair-round-counts')->assertExitCode(0);

        $this->assertSame(500, $noSignal->fresh()->round_count, 'no reliable count to repair from — left untouched');
        $this->assertSame(50, $alreadyCorrect->fresh()->round_count);
    }

    public function test_repair_command_only_touches_davidsons_offerings(): void
    {
        $other = Distributor::create([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'driver_class' => 'App\\Services\\Feeds\\Drivers\\RsrFeedDriver',
            'transport_type' => 'sftp',
            'connection_settings' => [],
        ]);
        $offering = $this->poisonedOffering($other, ['distributor_sku' => 'RSR-NOTDAVIDSONS']);

        $this->artisan('davidsons:repair-round-counts')->assertExitCode(0);

        $this->assertSame(500, $offering->fresh()->round_count);
        $this->assertTrue((bool) $offering->fresh()->needs_review);
    }
}
