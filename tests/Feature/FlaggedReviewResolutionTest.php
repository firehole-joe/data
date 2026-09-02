<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\DistributorSkuOverride;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\SupplyReportQueryService;
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

        $this->assertDatabaseHas('distributor_sku_overrides', [
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'BULK9',
            'round_count' => 50,
        ]);
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
}
