<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\DistributorSkuOverride;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\SupplyReportQueryService;
use App\Services\Feeds\DistributorSkuOverrideManager;
use App\Services\Feeds\Drivers\RsrFeedDriver;
use App\Services\Feeds\FeedIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class FlaggedReviewResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A single RSR-layout ammunition row whose $/round is out of band:
     * .22 LR (rimfire ceiling $0.60/rd) at $40.00 over 50 rounds = $0.80/rd.
     */
    private const OUT_OF_BAND_ROW =
        'TST22;;FED 22LR 40GR 50RD;18;;45.00;40.00;;25;;Federal;AE22LR;;Federal 22LR 40gr 50-round box';

    private function mockFeed(string $body): void
    {
        $mock = Mockery::mock(RsrFeedDriver::class.'[downloadFeed]');
        $mock->shouldReceive('downloadFeed')->andReturnUsing(function () use ($body): string {
            $tmp = tempnam(sys_get_temp_dir(), 'test_review_');
            file_put_contents($tmp, $body."\r\n");

            return $tmp;
        });

        $this->app->instance(RsrFeedDriver::class, $mock);
    }

    /**
     * A single RSR-layout ammunition row (14 semicolon-delimited columns)
     * with overridable SKU / UPC / description / wholesale price. By
     * default it is the out-of-band .22 LR row ($40.00 / 50 rd = $0.80/rd,
     * over the $0.60 rimfire ceiling).
     */
    private function rsrRow(array $o = []): string
    {
        $o = array_merge([
            'sku' => 'TST22',
            'upc' => '',
            'desc' => 'FED 22LR 40GR 50RD',
            'wholesale' => '40.00',
            'qty' => '25',
            'mfr' => 'Federal',
            'mpn' => 'AE22LR',
            'expanded' => 'Federal 22LR 40gr 50-round box',
        ], $o);

        return implode(';', [
            $o['sku'], $o['upc'], $o['desc'], '18', '', '45.00', $o['wholesale'],
            '', $o['qty'], '', $o['mfr'], $o['mpn'], '', $o['expanded'],
        ]);
    }

    private function ingest(Distributor $distributor): void
    {
        app(FeedIngestionService::class)->ingest($distributor);
    }

    private function distributor(array $overrides = []): Distributor
    {
        return Distributor::create(array_merge([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'driver_class' => RsrFeedDriver::class,
            'transport_type' => 'sftp',
            'connection_settings' => ['host' => 'sftp.example.test', 'username' => 'u'],
        ], $overrides));
    }

    private function master(array $overrides = []): MasterAmmunition
    {
        static $n = 0;
        $n++;

        return MasterAmmunition::create(array_merge([
            'manufacturer' => 'Magtech',
            'mfr_part_number' => "MPN-{$n}",
            'name' => "Master {$n}",
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 115,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 1000,
            'is_tracked_in_report' => true,
        ], $overrides));
    }

    private function flaggedOffering(MasterAmmunition $master, Distributor $distributor, array $overrides = []): DistributorProduct
    {
        return DistributorProduct::create(array_merge([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => 'BULK9',
            'raw_description' => 'RECLAIMED 9MM RANGE BRASS LOADED',
            'wholesale_price' => 12.88,
            'quantity_available' => 100,
            'is_in_stock' => true,
            'needs_review' => true,
            'review_reason' => '$0.0129/rd is below the rimfire floor of $0.02',
        ], $overrides));
    }

    private function filters(array $input = []): array
    {
        return app(SupplyReportQueryService::class)->normalizeFilters(new Request($input));
    }

    /* -------------------------------------------------------------- */
    /* Ingestion captures the diagnostic */
    /* -------------------------------------------------------------- */

    public function test_feed_ingestion_records_the_specific_guardrail_reason_on_the_offering(): void
    {
        $this->mockFeed(self::OUT_OF_BAND_ROW);
        $distributor = $this->distributor();

        app(FeedIngestionService::class)->ingest($distributor);

        $offering = DistributorProduct::where('distributor_sku', 'TST22')->firstOrFail();

        $this->assertTrue((bool) $offering->needs_review);
        $this->assertNotEmpty($offering->review_reason);
        $this->assertStringContainsString('exceeds the rimfire ceiling', (string) $offering->review_reason);
    }

    public function test_feed_ingestion_applies_an_existing_sku_override_and_does_not_flag(): void
    {
        $this->mockFeed(self::OUT_OF_BAND_ROW);
        $distributor = $this->distributor();

        // A reviewer confirmed this SKU is really 100 rounds/unit
        // ($40.00 / 100 = $0.40/rd — inside the rimfire band).
        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'TST22',
            'round_count' => 100,
        ]);

        app(FeedIngestionService::class)->ingest($distributor);

        $offering = DistributorProduct::where('distributor_sku', 'TST22')->firstOrFail();

        $this->assertFalse((bool) $offering->needs_review);
        $this->assertNull($offering->review_reason);
        $this->assertSame(100, $offering->round_count);
        $this->assertEqualsWithDelta(0.40, (float) $offering->cost_per_round, 0.0001);
    }

    /* -------------------------------------------------------------- */
    /* Approve */
    /* -------------------------------------------------------------- */

    public function test_approve_corrects_the_count_clears_the_flag_and_writes_an_override(): void
    {
        $distributor = $this->distributor();
        $master = $this->master(['caliber' => '9mm Luger', 'rounds_per_box' => 1000]);
        $offering = $this->flaggedOffering($master, $distributor);

        $response = $this->withSession(['feed_admin_authenticated' => true])
            ->patch(route('supply.offerings.approve', $offering), ['round_count' => 50]);

        $response->assertRedirect(route('supply.dashboard'));

        $fresh = $offering->fresh();
        $this->assertFalse((bool) $fresh->needs_review);
        $this->assertNull($fresh->review_reason);
        $this->assertSame(50, $fresh->round_count);
        $this->assertEqualsWithDelta(0.2576, (float) $fresh->cost_per_round, 0.0001);

        $override = DistributorSkuOverride::where('distributor_sku', 'BULK9')->firstOrFail();
        $this->assertSame(50, $override->round_count);
        $this->assertFalse((bool) $override->is_ignored);
        $this->assertEqualsWithDelta(12.88, (float) $override->baseline_price, 0.0001);
        $this->assertSame('RECLAIMED 9MM RANGE BRASS LOADED', $override->baseline_description);
    }

    public function test_approve_refreshes_the_parent_best_price_rollup(): void
    {
        $distributor = $this->distributor();
        $master = $this->master(['caliber' => '9mm Luger', 'rounds_per_box' => 50]);

        // The trustworthy offering sets the current best.
        DistributorProduct::create([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => 'CLEAN9',
            'raw_description' => 'clean 9mm',
            'wholesale_price' => 12.00,
            'quantity_available' => 10,
            'is_in_stock' => true,
        ]);
        // A flagged, cheaper offering held out of the rollup until approved.
        $flagged = $this->flaggedOffering($master, $distributor, [
            'distributor_sku' => 'CHEAP9',
            'wholesale_price' => 9.00,
        ]);

        $before = app(SupplyReportQueryService::class)->paginate($this->filters())->first();
        $this->assertSame(12.00, $before->best_price_per_box);

        $this->withSession(['feed_admin_authenticated' => true])
            ->patch(route('supply.offerings.approve', $flagged), ['round_count' => 50])
            ->assertRedirect();

        $after = app(SupplyReportQueryService::class)->paginate($this->filters())->first();
        $this->assertSame(9.00, $after->best_price_per_box, 'approved offering now competes for best price');
        $this->assertSame(0, $after->review_count);
        $this->assertSame(0, app(SupplyReportQueryService::class)->stats($this->filters())['needs_review']);
    }

    public function test_approve_is_gated_behind_the_feed_admin_passphrase(): void
    {
        $distributor = $this->distributor();
        $offering = $this->flaggedOffering($this->master(), $distributor);

        $this->patch(route('supply.offerings.approve', $offering), ['round_count' => 50])
            ->assertRedirect(route('admin.unlock'));

        $this->assertTrue((bool) $offering->fresh()->needs_review);
        $this->assertDatabaseCount('distributor_sku_overrides', 0);
    }

    public function test_approve_validates_the_round_count(): void
    {
        $distributor = $this->distributor();
        $offering = $this->flaggedOffering($this->master(), $distributor);

        $this->withSession(['feed_admin_authenticated' => true])
            ->patch(route('supply.offerings.approve', $offering), ['round_count' => 0])
            ->assertSessionHasErrors('round_count');

        $this->assertTrue((bool) $offering->fresh()->needs_review);
    }

    /* -------------------------------------------------------------- */
    /* Ignore */
    /* -------------------------------------------------------------- */

    public function test_ignore_hides_the_offering_from_the_flagged_view_and_market_calculations(): void
    {
        $distributor = $this->distributor();
        $master = $this->master(['caliber' => '9mm Luger', 'rounds_per_box' => 50]);
        $offering = $this->flaggedOffering($master, $distributor, ['wholesale_price' => 3.00]);

        $this->withSession(['feed_admin_authenticated' => true])
            ->patch(route('supply.offerings.ignore', $offering))
            ->assertRedirect(route('supply.dashboard'));

        $fresh = $offering->fresh();
        $this->assertTrue((bool) $fresh->is_ignored);
        $this->assertFalse((bool) $fresh->needs_review);

        // Durable ledger entry so the dismissal survives future imports.
        $this->assertDatabaseHas('distributor_sku_overrides', [
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'BULK9',
            'is_ignored' => true,
        ]);

        $service = app(SupplyReportQueryService::class);
        $this->assertCount(0, $service->paginate($this->filters(['review' => 'flagged'])));
        $this->assertSame(0, $service->stats($this->filters())['needs_review']);
        $this->assertSame(0, $service->stats($this->filters())['offer_count']);
    }

    public function test_ignore_is_gated_behind_the_feed_admin_passphrase(): void
    {
        $distributor = $this->distributor();
        $offering = $this->flaggedOffering($this->master(), $distributor);

        $this->patch(route('supply.offerings.ignore', $offering))
            ->assertRedirect(route('admin.unlock'));

        $this->assertFalse((bool) $offering->fresh()->is_ignored);
    }

    /* -------------------------------------------------------------- */
    /* Drawer */
    /* -------------------------------------------------------------- */

    public function test_drawer_shows_diagnostics_and_resolution_controls_when_unlocked(): void
    {
        $distributor = $this->distributor();
        $master = $this->master(['name' => 'FLAGGED DRAWER ITEM', 'caliber' => '9mm Luger']);
        $this->flaggedOffering($master, $distributor, [
            'raw_description' => 'RECLAIMED 9MM RANGE BRASS LOADED',
            'review_reason' => '$0.0129/rd is below the centerfire_handgun floor of $0.08',
        ]);

        $this->withSession(['feed_admin_authenticated' => true])
            ->get(route('supply.dashboard', ['review' => 'flagged']))
            ->assertOk()
            ->assertSee('below the centerfire_handgun floor')
            ->assertSee('RECLAIMED 9MM RANGE BRASS LOADED')
            ->assertSee('BULK9')
            ->assertSee('Rounds per Unit')
            ->assertSee('Recalculated $ / Round')
            ->assertSee('Approve')
            ->assertSee('Ignore');
    }

    public function test_drawer_hides_controls_and_prompts_to_unlock_when_locked(): void
    {
        $distributor = $this->distributor();
        $master = $this->master(['caliber' => '9mm Luger']);
        $this->flaggedOffering($master, $distributor);

        $this->get(route('supply.dashboard', ['review' => 'flagged']))
            ->assertOk()
            ->assertSee('Unlock feed admin')
            ->assertDontSee('Recalculated $ / Round');
    }

    /* -------------------------------------------------------------- */
    /* Durable persistence across future feed imports */
    /* -------------------------------------------------------------- */

    public function test_ignored_state_persists_across_a_future_feed_upsert_by_sku(): void
    {
        $distributor = $this->distributor();

        // First import flags it; a reviewer ignores it.
        $this->mockFeed($this->rsrRow());
        $this->ingest($distributor);

        $offering = DistributorProduct::where('distributor_sku', 'TST22')->firstOrFail();
        $this->assertTrue((bool) $offering->needs_review);

        $this->withSession(['feed_admin_authenticated' => true])
            ->patch(route('supply.offerings.ignore', $offering))
            ->assertRedirect();

        // A later feed run re-upserts the same (still out-of-band) row.
        $this->mockFeed($this->rsrRow());
        $this->ingest($distributor);

        $fresh = DistributorProduct::where('distributor_sku', 'TST22')->firstOrFail();
        $this->assertTrue((bool) $fresh->is_ignored);
        $this->assertFalse((bool) $fresh->needs_review);
        $this->assertNull($fresh->review_reason);
    }

    public function test_ignored_state_persists_by_upc_even_when_the_distributor_renumbers_the_sku(): void
    {
        $distributor = $this->distributor();

        // A ledger row ignoring this UPC, recorded against the old SKU.
        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'OLDNUM-1',
            'upc' => '604544617375',
            'is_ignored' => true,
            'round_count' => 0,
            'baseline_price' => 40.00,
            'baseline_description' => 'Federal 22LR 40gr 50-round box',
        ]);

        // The feed now carries the same product under a brand-new SKU.
        $this->mockFeed($this->rsrRow(['sku' => 'RENUM-2', 'upc' => '604544617375']));
        $this->ingest($distributor);

        $offering = DistributorProduct::where('distributor_sku', 'RENUM-2')->firstOrFail();
        $this->assertTrue((bool) $offering->is_ignored, 'UPC match on the ignore ledger must carry over');
        $this->assertFalse((bool) $offering->needs_review);
    }

    public function test_approved_round_count_reapplies_automatically_when_nothing_material_changed(): void
    {
        $distributor = $this->distributor();

        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'TST22',
            'round_count' => 100,
            'is_ignored' => false,
            'baseline_price' => 40.00,
            'baseline_description' => 'Federal 22LR 40gr 50-round box',
        ]);

        // Same price, same packaging string as the snapshot.
        $this->mockFeed($this->rsrRow());
        $this->ingest($distributor);

        $offering = DistributorProduct::where('distributor_sku', 'TST22')->firstOrFail();
        $this->assertFalse((bool) $offering->needs_review, 'no drift — the saved correction is re-applied silently');
        $this->assertNull($offering->review_reason);
        $this->assertSame(100, $offering->round_count);
        $this->assertEqualsWithDelta(0.40, (float) $offering->cost_per_round, 0.0001);
    }

    /* -------------------------------------------------------------- */
    /* Change-detection resurfacing (data drift) */
    /* -------------------------------------------------------------- */

    public function test_a_material_price_move_resurfaces_an_approved_override(): void
    {
        $distributor = $this->distributor();

        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'TST22',
            'round_count' => 100,
            'is_ignored' => false,
            'baseline_price' => 40.00,
            'baseline_description' => 'Federal 22LR 40gr 50-round box',
        ]);

        // Wholesale cost jumps 50% (> 15% drift threshold).
        $this->mockFeed($this->rsrRow(['wholesale' => '60.00']));
        $this->ingest($distributor);

        $offering = DistributorProduct::where('distributor_sku', 'TST22')->firstOrFail();
        $this->assertTrue((bool) $offering->needs_review);
        $this->assertStringContainsString('re-check', (string) $offering->review_reason);
        $this->assertStringContainsString('wholesale cost moved', (string) $offering->review_reason);
        $this->assertSame(100, $offering->round_count, 'last-good count stays pinned while it is re-reviewed');
    }

    public function test_a_changed_feed_description_resurfaces_an_approved_override(): void
    {
        $distributor = $this->distributor();

        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'TST22',
            'round_count' => 100,
            'is_ignored' => false,
            'baseline_price' => 40.00,
            'baseline_description' => 'Federal 22LR 40gr 50-round box',
        ]);

        // Same price, but the packaging string changed.
        $this->mockFeed($this->rsrRow(['expanded' => 'Federal 22LR 40gr NICKEL 50-round box']));
        $this->ingest($distributor);

        $offering = DistributorProduct::where('distributor_sku', 'TST22')->firstOrFail();
        $this->assertTrue((bool) $offering->needs_review);
        $this->assertStringContainsString('description changed', (string) $offering->review_reason);
    }

    public function test_a_small_price_wiggle_does_not_resurface_an_approved_override(): void
    {
        $distributor = $this->distributor();

        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'TST22',
            'round_count' => 100,
            'is_ignored' => false,
            'baseline_price' => 40.00,
            'baseline_description' => 'Federal 22LR 40gr 50-round box',
        ]);

        // +10% — under the 15% resurfacing threshold.
        $this->mockFeed($this->rsrRow(['wholesale' => '44.00']));
        $this->ingest($distributor);

        $offering = DistributorProduct::where('distributor_sku', 'TST22')->firstOrFail();
        $this->assertFalse((bool) $offering->needs_review);
        $this->assertSame(100, $offering->round_count);
        $this->assertEqualsWithDelta(0.44, (float) $offering->cost_per_round, 0.0001);
    }

    public function test_backfill_command_honours_the_ignore_ledger_and_resurfaces_drift(): void
    {
        $distributor = $this->distributor(['slug' => 'rsr']);

        $ignored = $this->flaggedOffering($this->master(['caliber' => '9mm Luger']), $distributor, [
            'distributor_sku' => 'IGN1',
            'wholesale_price' => 3.00,
        ]);
        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'IGN1',
            'is_ignored' => true,
            'round_count' => 0,
            'baseline_price' => 3.00,
            'baseline_description' => $ignored->raw_description,
        ]);

        $drifted = $this->flaggedOffering($this->master(['caliber' => '9mm Luger']), $distributor, [
            'distributor_sku' => 'DRF1',
            'raw_description' => 'MAGTECH 9MM 115GR FMJ 50RD',
            'wholesale_price' => 30.00,
            'needs_review' => false,
        ]);
        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'DRF1',
            'round_count' => 50,
            'is_ignored' => false,
            'baseline_price' => 12.00,
            'baseline_description' => 'MAGTECH 9MM 115GR FMJ 50RD',
        ]);

        $this->artisan('ammo:backfill-attributes')->assertExitCode(0);

        $this->assertTrue((bool) $ignored->fresh()->is_ignored);
        $this->assertFalse((bool) $ignored->fresh()->needs_review);

        $this->assertTrue((bool) $drifted->fresh()->needs_review, 'price drifted 150% from the $12 baseline');
        $this->assertStringContainsString('re-check', (string) $drifted->fresh()->review_reason);
    }

    /* -------------------------------------------------------------- */
    /* Bulk "Ignore All Remaining" */
    /* -------------------------------------------------------------- */

    public function test_ignore_all_dismisses_every_flagged_offering_and_writes_the_ledger(): void
    {
        $distributor = $this->distributor();
        $master = $this->master(['caliber' => '9mm Luger']);

        $a = $this->flaggedOffering($master, $distributor, ['distributor_sku' => 'F1', 'raw_upc' => '111111111111']);
        $b = $this->flaggedOffering($master, $distributor, ['distributor_sku' => 'F2', 'raw_upc' => '222222222222']);
        $clean = DistributorProduct::create([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => 'OK1',
            'raw_description' => 'clean 9mm',
            'wholesale_price' => 12.00,
            'quantity_available' => 10,
            'is_in_stock' => true,
        ]);

        $this->withSession(['feed_admin_authenticated' => true])
            ->post(route('supply.offerings.ignore_all', ['review' => 'flagged']))
            ->assertRedirect()
            ->assertSessionHas('success', 'Successfully ignored 2 reviewable items.');

        foreach ([$a, $b] as $offering) {
            $this->assertTrue((bool) $offering->fresh()->is_ignored);
            $this->assertFalse((bool) $offering->fresh()->needs_review);
            $this->assertDatabaseHas('distributor_sku_overrides', [
                'distributor_id' => $distributor->id,
                'distributor_sku' => $offering->distributor_sku,
                'is_ignored' => true,
            ]);
        }

        $this->assertFalse((bool) $clean->fresh()->is_ignored);
        $this->assertDatabaseMissing('distributor_sku_overrides', ['distributor_sku' => 'OK1']);
    }

    public function test_ignore_all_respects_the_active_distributor_filter(): void
    {
        $rsr = $this->distributor(['slug' => 'rsr', 'name' => 'RSR']);
        $lip = $this->distributor(['slug' => 'lipseys', 'name' => 'Lipseys']);
        $master = $this->master(['caliber' => '9mm Luger']);

        $rsrOffering = $this->flaggedOffering($master, $rsr, ['distributor_sku' => 'R1']);
        $lipOffering = $this->flaggedOffering($master, $lip, ['distributor_sku' => 'L1']);

        $this->withSession(['feed_admin_authenticated' => true])
            ->post(route('supply.offerings.ignore_all', ['review' => 'flagged', 'distributors' => [$rsr->id]]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Successfully ignored 1 reviewable item.');

        $this->assertTrue((bool) $rsrOffering->fresh()->is_ignored);
        $this->assertFalse((bool) $lipOffering->fresh()->is_ignored, 'outside the active distributor selection');
        $this->assertTrue((bool) $lipOffering->fresh()->needs_review);
    }

    public function test_ignore_all_is_gated_behind_the_feed_admin_passphrase(): void
    {
        $distributor = $this->distributor();
        $offering = $this->flaggedOffering($this->master(), $distributor);

        $this->post(route('supply.offerings.ignore_all'))
            ->assertRedirect(route('admin.unlock'));

        $this->assertFalse((bool) $offering->fresh()->is_ignored);
        $this->assertTrue((bool) $offering->fresh()->needs_review);
        $this->assertDatabaseCount('distributor_sku_overrides', 0);
    }

    public function test_dashboard_shows_the_bulk_ignore_button_when_unlocked_on_the_flagged_view(): void
    {
        $distributor = $this->distributor();
        $this->flaggedOffering($this->master(['caliber' => '9mm Luger']), $distributor);

        $this->withSession(['feed_admin_authenticated' => true])
            ->get(route('supply.dashboard', ['review' => 'flagged']))
            ->assertOk()
            ->assertSee('Ignore All Remaining');
    }

    public function test_dashboard_hides_the_bulk_ignore_button_when_locked(): void
    {
        $distributor = $this->distributor();
        $this->flaggedOffering($this->master(['caliber' => '9mm Luger']), $distributor);

        $this->get(route('supply.dashboard', ['review' => 'flagged']))
            ->assertOk()
            ->assertDontSee('Ignore All Remaining');
    }

    public function test_dashboard_hides_the_bulk_ignore_button_off_the_flagged_view(): void
    {
        $distributor = $this->distributor();
        $this->flaggedOffering($this->master(['caliber' => '9mm Luger']), $distributor);

        $this->withSession(['feed_admin_authenticated' => true])
            ->get(route('supply.dashboard', ['review' => 'all']))
            ->assertOk()
            ->assertDontSee('Ignore All Remaining');
    }

    public function test_override_manager_matches_by_sku_before_upc(): void
    {
        $distributor = $this->distributor();
        $master = $this->master(['caliber' => '9mm Luger', 'upc' => '333333333333']);
        $offering = $this->flaggedOffering($master, $distributor, [
            'distributor_sku' => 'PRIMARY',
            'raw_upc' => '333333333333',
        ]);

        // A UPC-only "ignore" row and an exact SKU "approve" row both match;
        // the exact distributor + SKU match must win.
        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'SOME-OTHER-SKU',
            'upc' => '333333333333',
            'is_ignored' => true,
            'round_count' => 0,
        ]);
        DistributorSkuOverride::create([
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'PRIMARY',
            'round_count' => 25,
            'is_ignored' => false,
        ]);

        $decision = app(DistributorSkuOverrideManager::class)->resolve($offering);

        $this->assertFalse($decision['ignored']);
        $this->assertSame(25, $decision['round_count']);
    }
}
