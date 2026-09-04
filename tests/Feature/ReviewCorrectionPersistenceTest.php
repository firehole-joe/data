<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Models\User;
use App\Services\Ammunition\SupplyReportQueryService;
use App\Services\Feeds\Drivers\RsrFeedDriver;
use App\Services\Feeds\FeedIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ReviewCorrectionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** A shared UPC carried by both the 50-round boxes and the 1000-round case. */
    private const SHARED_UPC = '754908114016';

    /**
     * One RSR-layout row: 14 semicolon-delimited columns
     * (sku; upc; desc; dept; ; msrp; wholesale; ; qty; ; mfr; mpn; ; expanded).
     */
    private function rsrRow(array $o): string
    {
        $o = array_merge([
            'upc' => self::SHARED_UPC,
            'qty' => '40',
            'mfr' => 'Magtech',
            'mpn' => 'MP-9MM',
            'expanded' => $o['desc'] ?? '',
        ], $o);

        return implode(';', [
            $o['sku'], $o['upc'], $o['desc'], '18', '', '25.00', $o['wholesale'],
            '', $o['qty'], '', $o['mfr'], $o['mpn'], '', $o['expanded'],
        ]);
    }

    private function mockFeed(string ...$rows): void
    {
        $body = implode("\r\n", $rows)."\r\n";

        $mock = Mockery::mock(RsrFeedDriver::class.'[downloadFeed]');
        $mock->shouldReceive('downloadFeed')->andReturnUsing(function () use ($body): string {
            $tmp = tempnam(sys_get_temp_dir(), 'test_case_poison_');
            file_put_contents($tmp, $body);

            return $tmp;
        });

        $this->app->instance(RsrFeedDriver::class, $mock);
    }

    private function distributor(): Distributor
    {
        return Distributor::firstOrCreate(['slug' => 'rsr'], [
            'name' => 'RSR Group',
            'driver_class' => RsrFeedDriver::class,
            'transport_type' => 'sftp',
            'connection_settings' => ['host' => 'sftp.example.test', 'username' => 'u'],
        ]);
    }

    private function ingest(Distributor $distributor): void
    {
        app(FeedIngestionService::class)->ingest($distributor);
    }

    private function filters(array $input = []): array
    {
        return app(SupplyReportQueryService::class)->normalizeFilters(new Request($input));
    }

    private function cprFor(string $sku): ?float
    {
        $offering = DistributorProduct::where('distributor_sku', $sku)->firstOrFail();

        return $offering->cost_per_round !== null ? round((float) $offering->cost_per_round, 4) : null;
    }

    /* -------------------------------------------------------------- */
    /*  Case SKU must not poison the box SKU's $/round */
    /* -------------------------------------------------------------- */

    public function test_ingesting_the_case_sku_after_the_box_does_not_break_the_box_cpr(): void
    {
        $distributor = $this->distributor();

        // First feed: only the 50-round retail box.
        $this->mockFeed($this->rsrRow([
            'sku' => 'MG9A',
            'desc' => 'MAGTECH 9MM LUGER 124GR FMJ 50/1000',
            'wholesale' => '12.88',
        ]));
        $this->ingest($distributor);

        $box = DistributorProduct::where('distributor_sku', 'MG9A')->firstOrFail();
        $master = $box->masterAmmunition;
        $this->assertSame(50, (int) $master->rounds_per_box, 'plain box SKU seeds the box count');
        $this->assertEqualsWithDelta(0.2576, $this->cprFor('MG9A'), 0.001);

        // Second feed: the 1000-round case, same UPC, at a case price.
        $this->mockFeed($this->rsrRow([
            'sku' => 'MG9A-CSDP',
            'desc' => 'MAGTECH 9MM LUGER 124GR FMJ 50/1000 CASE',
            'wholesale' => '257.65',
        ]));
        $this->ingest($distributor);

        $case = DistributorProduct::where('distributor_sku', 'MG9A-CSDP')->firstOrFail();
        $this->assertSame($master->id, $case->master_ammunition_id, 'the case matched the shared UPC');

        // The master's box count is untouched…
        $this->assertSame(50, (int) $master->fresh()->rounds_per_box);
        // …the box still divides by 50…
        $this->assertEqualsWithDelta(0.2576, $this->cprFor('MG9A'), 0.001);
        $this->assertFalse((bool) $box->fresh()->needs_review);
        // …and the case keeps its own 1000-round count.
        $this->assertSame(1000, (int) $case->round_count);
        $this->assertEqualsWithDelta(0.2577, $this->cprFor('MG9A-CSDP'), 0.001);
    }

    public function test_case_created_first_is_reconciled_down_when_the_box_arrives(): void
    {
        $distributor = $this->distributor();

        // A single feed carrying the case row before the box row.
        $this->mockFeed(
            $this->rsrRow([
                'sku' => 'MG9A-CSDP',
                'desc' => 'MAGTECH 9MM LUGER 124GR FMJ 50/1000 CASE',
                'wholesale' => '257.65',
            ]),
            $this->rsrRow([
                'sku' => 'MG9A',
                'desc' => 'MAGTECH 9MM LUGER 124GR FMJ 50/1000',
                'wholesale' => '12.88',
            ]),
        );
        $this->ingest($distributor);

        $box = DistributorProduct::where('distributor_sku', 'MG9A')->firstOrFail();

        // The case seeded the master at 1000, but reconciliation pulled it
        // back to the box count the boxes describe.
        $this->assertSame(50, (int) $box->masterAmmunition->rounds_per_box);
        $this->assertEqualsWithDelta(0.2576, $this->cprFor('MG9A'), 0.001);
        $this->assertFalse((bool) $box->fresh()->needs_review, 'the box is no longer a fraction of a cent per round');

        // The dashboard "lowest 9mm $/rd" reflects the box, not the case-count parse.
        $stats = app(SupplyReportQueryService::class)->stats($this->filters());
        $this->assertGreaterThanOrEqual(0.05, $stats['cpr']['min']);
    }

    public function test_a_no_count_box_on_a_poisoned_master_is_flagged_not_silently_mispriced(): void
    {
        $distributor = $this->distributor();

        // Case first (seeds master = 1000), then a box with no packaging
        // cue at all — nothing can correct it, so it must be quarantined.
        $this->mockFeed(
            $this->rsrRow([
                'sku' => 'MG9A-CSDP',
                'desc' => 'MAGTECH 9MM LUGER 124GR FMJ 1000RD CASE',
                'wholesale' => '257.65',
            ]),
            $this->rsrRow([
                'sku' => 'MG9A-BULK',
                'desc' => 'MAGTECH 9MM RANGE PACK',
                'wholesale' => '12.88',
            ]),
        );
        $this->ingest($distributor);

        $bulk = DistributorProduct::where('distributor_sku', 'MG9A-BULK')->firstOrFail();
        $this->assertTrue((bool) $bulk->needs_review);
        $this->assertStringContainsString('centerfire floor', (string) $bulk->review_reason);

        // And it never counts as the market low.
        $stats = app(SupplyReportQueryService::class)->stats($this->filters());
        $this->assertTrue($stats['cpr']['min'] === null || $stats['cpr']['min'] >= 0.05);
    }

    /* -------------------------------------------------------------- */
    /*  Review correction persists to the ledger + recomputes CPR */
    /* -------------------------------------------------------------- */

    public function test_a_review_correction_writes_the_override_and_recalculates_cpr(): void
    {
        $distributor = $this->distributor();

        $master = MasterAmmunition::create([
            'upc' => self::SHARED_UPC,
            'manufacturer' => 'Magtech',
            'mfr_part_number' => 'MP-9MM',
            'name' => 'Magtech 9mm 124gr FMJ',
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 124,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 1000, // poisoned by an earlier case ingest
            'is_tracked_in_report' => true,
        ]);

        $offering = DistributorProduct::create([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => 'MT9A',
            'raw_upc' => self::SHARED_UPC,
            'raw_description' => 'MAGTECH 9MM LUGER 124GR FMJ',
            'wholesale_price' => 12.88,
            'quantity_available' => 40,
            'is_in_stock' => true,
            'needs_review' => true,
            'review_reason' => '$0.0129/rd is below the $0.05 centerfire floor',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('supply.offerings.approve', $offering), ['round_count' => 50])
            ->assertRedirect(route('supply.dashboard'));

        // 1. The durable ledger row exists with the confirmed count.
        $this->assertDatabaseHas('distributor_sku_overrides', [
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'MT9A',
            'round_count' => 50,
            'is_ignored' => false,
        ]);

        // 2. The offering's own count + CPR were recalculated.
        $fresh = $offering->fresh();
        $this->assertSame(50, (int) $fresh->round_count);
        $this->assertEqualsWithDelta(0.2576, (float) $fresh->cost_per_round, 0.0001);
        $this->assertFalse((bool) $fresh->needs_review);

        // 3. The master, pinned to a case count, was corrected to the box count.
        $this->assertSame(50, (int) $master->fresh()->rounds_per_box);
    }

    public function test_recalculate_pricing_command_repairs_a_lingering_low_cpr_offering(): void
    {
        $distributor = $this->distributor();

        $master = MasterAmmunition::create([
            'upc' => self::SHARED_UPC,
            'manufacturer' => 'Magtech',
            'mfr_part_number' => 'MP-9MM',
            'name' => 'Magtech 9mm 124gr FMJ',
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 124,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 1000,
            'is_tracked_in_report' => true,
        ]);

        // A box that DOES describe its count, but was ingested before the
        // fix and still carries a broken CPR and no pinned round count.
        $box = DistributorProduct::create([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => 'MG9A',
            'raw_upc' => self::SHARED_UPC,
            'raw_description' => 'MAGTECH 9MM LUGER 124GR FMJ 50RD BOX',
            'wholesale_price' => 12.88,
            'cost_per_round' => 0.0129,
            'quantity_available' => 40,
            'is_in_stock' => true,
        ]);

        $this->artisan('ammo:recalculate-pricing')
            ->expectsOutputToContain('rounds_per_box 1000')
            ->assertExitCode(0);

        $this->assertSame(50, (int) $master->fresh()->rounds_per_box);

        $fresh = $box->fresh();
        $this->assertSame(50, (int) $fresh->round_count);
        $this->assertEqualsWithDelta(0.2576, (float) $fresh->cost_per_round, 0.0001);
        $this->assertFalse((bool) $fresh->needs_review);
    }

    public function test_recalculate_pricing_dry_run_writes_nothing(): void
    {
        $distributor = $this->distributor();

        $master = MasterAmmunition::create([
            'upc' => self::SHARED_UPC,
            'manufacturer' => 'Magtech',
            'mfr_part_number' => 'MP-9MM',
            'name' => 'Magtech 9mm',
            'caliber' => '9mm Luger',
            'rounds_per_box' => 1000,
            'is_tracked_in_report' => true,
        ]);

        DistributorProduct::create([
            'distributor_id' => $distributor->id,
            'master_ammunition_id' => $master->id,
            'distributor_sku' => 'MG9A',
            'raw_description' => 'MAGTECH 9MM LUGER 124GR FMJ 50RD BOX',
            'wholesale_price' => 12.88,
            'cost_per_round' => 0.0129,
            'quantity_available' => 40,
            'is_in_stock' => true,
        ]);

        $this->artisan('ammo:recalculate-pricing', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(1000, (int) $master->fresh()->rounds_per_box);
        $this->assertEqualsWithDelta(0.0129, (float) DistributorProduct::first()->cost_per_round, 0.0001);
    }
}
