<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Feeds\Drivers\RsrFeedDriver;
use App\Services\Feeds\FeedIngestionService;
use App\Services\Matching\ProductMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductMatchingTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/rsr_sample_feed.txt';

    private ProductMatchingService $matcher;

    private Distributor $distributor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = $this->app->make(ProductMatchingService::class);
        $this->distributor = Distributor::create([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'driver_class' => RsrFeedDriver::class,
            'transport_type' => 'sftp',
            'connection_settings' => ['host' => 'sftp.example.test'],
        ]);
    }

    private function master(array $overrides = []): MasterAmmunition
    {
        return MasterAmmunition::create(array_merge([
            'upc' => null,
            'manufacturer' => 'Federal',
            'mfr_part_number' => 'AE223J',
            'name' => 'Federal American Eagle .223 Rem 55gr FMJ',
            'caliber' => '.223 Remington',
            'bullet_weight_gr' => 55,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 20,
        ], $overrides));
    }

    private function product(array $overrides = []): DistributorProduct
    {
        static $n = 0;
        $n++;

        return DistributorProduct::create(array_merge([
            'distributor_id' => $this->distributor->id,
            'distributor_sku' => 'SKU-'.$n,
            'raw_upc' => null,
            'raw_mfr_part_number' => null,
            'raw_description' => 'Unknown item',
            'wholesale_price' => 1.00,
            'quantity_available' => 10,
            'is_in_stock' => true,
        ], $overrides));
    }

    /**
     * @param  array<int, string>  $descriptions
     */
    private function seedAutoCreatable(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->product([
                'raw_mfr_part_number' => 'MPN-'.$i,
                'raw_description' => "Federal .223 Remington 55gr FMJ 20rd lot {$i}",
            ]);
        }
    }

    /* ---------------------------------------------------------------- */
    /*  Tier 1 — UPC */
    /* ---------------------------------------------------------------- */

    public function test_tier_1_matches_on_upc_even_when_the_raw_value_is_dirty(): void
    {
        $master = $this->master(['upc' => '604544617375']);
        $product = $this->product([
            'raw_upc' => '6-0454-461 7375',
            'raw_description' => 'some vendor description that should be ignored',
        ]);

        $result = $this->matcher->matchProduct($product);

        $this->assertNotNull($result);
        $this->assertSame($master->id, $result->id);
        $this->assertSame($master->id, $product->fresh()->master_ammunition_id);
        $this->assertSame(1, $this->matcher->getStats()['matched_upc']);
        $this->assertSame(0, $this->matcher->getStats()['created']);
    }

    /* ---------------------------------------------------------------- */
    /*  Tier 2 — [manufacturer, mfr_part_number] */
    /* ---------------------------------------------------------------- */

    public function test_tier_2_matches_on_parsed_manufacturer_and_raw_part_number(): void
    {
        $master = $this->master([
            'upc' => null,
            'manufacturer' => 'Federal',
            'mfr_part_number' => 'AE223J',
        ]);

        $product = $this->product([
            'raw_upc' => null,
            'raw_mfr_part_number' => 'AE223J',
            'raw_description' => 'Federal American Eagle .223 Rem 55gr FMJ 20rd',
        ]);

        $result = $this->matcher->matchProduct($product);

        $this->assertNotNull($result);
        $this->assertSame($master->id, $result->id);
        $this->assertSame($master->id, $product->fresh()->master_ammunition_id);
        $this->assertSame(1, $this->matcher->getStats()['matched_mpn']);
        $this->assertSame(0, $this->matcher->getStats()['created']);
        $this->assertSame(1, MasterAmmunition::count());
    }

    /* ---------------------------------------------------------------- */
    /*  Tier 3 — auto-create */
    /* ---------------------------------------------------------------- */

    public function test_tier_3_creates_a_canonical_master_from_the_parsed_description(): void
    {
        $product = $this->product([
            'raw_upc' => null,
            'raw_mfr_part_number' => 'E10MMFT3-20',
            'raw_description' => 'Sig Sauer Elite Performance 10mm Auto 180gr JHP 20rd',
        ]);

        $this->assertSame(0, MasterAmmunition::count());

        $result = $this->matcher->matchProduct($product);

        $this->assertNotNull($result);
        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertSame('10mm Auto', $result->caliber);
        $this->assertSame(180, $result->bullet_weight_gr);
        $this->assertSame('JHP', $result->bullet_type);
        $this->assertSame('Sig Sauer', $result->manufacturer);
        $this->assertSame('E10MMFT3-20', $result->mfr_part_number);
        $this->assertSame(20, $result->rounds_per_box);
        $this->assertSame($result->id, $product->fresh()->master_ammunition_id);
        $this->assertSame(1, $this->matcher->getStats()['created']);
    }

    public function test_tier_3_uses_the_box_count_for_slash_packaging_notation(): void
    {
        // Reported bug: "50/1000" on a plain box SKU was grabbing the case
        // count (1000), turning a $12.88 box of 50 into $0.013/round.
        $product = $this->product([
            'raw_upc' => null,
            'raw_mfr_part_number' => 'MT9A',
            'distributor_sku' => 'MT9A',
            'raw_description' => 'MAGTECH 9MM LUGER 115GR FMJ 50/1000',
            'wholesale_price' => 12.88,
        ]);

        $master = $this->matcher->matchProduct($product);

        $this->assertNotNull($master);
        $this->assertTrue($master->wasRecentlyCreated);
        $this->assertSame(50, $master->rounds_per_box);
        $this->assertEqualsWithDelta(
            0.258,
            round((float) $product->fresh()->wholesale_price / $master->rounds_per_box, 3),
            0.001,
        );
    }

    public function test_tier_3_uses_the_case_count_when_the_sku_flags_a_case(): void
    {
        $product = $this->product([
            'raw_upc' => null,
            'raw_mfr_part_number' => 'MT9A-CS',
            'distributor_sku' => 'MT9A-CS',
            'raw_description' => 'MAGTECH 9MM LUGER 115GR FMJ 50/1000',
            'wholesale_price' => 257.60,
        ]);

        $master = $this->matcher->matchProduct($product);

        $this->assertNotNull($master);
        $this->assertSame(1000, $master->rounds_per_box);
    }

    public function test_tier_3_brands_the_new_master_from_the_feed_manufacturer_column(): void
    {
        // Description names no brand the parser knows; the feed's own
        // manufacturer column does.
        $product = $this->product([
            'raw_upc' => null,
            'raw_mfr_part_number' => 'PP9MM',
            'raw_manufacturer' => 'PPU',
            'raw_description' => 'Tac-Load 9mm Luger 124gr FMJ 50rd',
        ]);

        $result = $this->matcher->matchProduct($product);

        $this->assertNotNull($result);
        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertSame('Prvi Partizan', $result->manufacturer);
        $this->assertNotSame('Unknown', $result->manufacturer);
        $this->assertSame('9mm Luger', $result->caliber);
    }

    public function test_tier_2_matches_on_the_feed_manufacturer_column(): void
    {
        $master = $this->master([
            'upc' => null,
            'manufacturer' => 'Prvi Partizan',
            'mfr_part_number' => 'PP9MM',
            'caliber' => '9mm Luger',
        ]);

        $product = $this->product([
            'raw_upc' => null,
            'raw_mfr_part_number' => 'PP9MM',
            'raw_manufacturer' => 'PPU',
            'raw_description' => 'Tac-Load 9mm Luger 124gr FMJ 50rd',
        ]);

        $result = $this->matcher->matchProduct($product);

        $this->assertSame($master->id, $result?->id);
        $this->assertSame(1, $this->matcher->getStats()['matched_mpn']);
        $this->assertSame(1, MasterAmmunition::count());
    }

    public function test_tier_3_is_skipped_when_auto_create_is_disabled(): void
    {
        $product = $this->product([
            'raw_upc' => null,
            'raw_mfr_part_number' => 'PMC9A',
            'raw_description' => 'PMC Bronze 9mm 115gr FMJ 50rd',
        ]);

        $result = $this->matcher->matchProduct($product, autoCreate: false);

        $this->assertNull($result);
        $this->assertSame(0, MasterAmmunition::count());
        $this->assertNull($product->fresh()->master_ammunition_id);
        $this->assertSame(1, $this->matcher->getStats()['unmatched']);
    }

    public function test_tier_3_reuses_an_existing_master_for_the_same_manufacturer_and_part_number(): void
    {
        $first = $this->product([
            'raw_mfr_part_number' => 'Q4172',
            'raw_description' => 'Winchester USA 9mm Luger 115gr FMJ 50rd',
        ]);
        $second = $this->product([
            'raw_mfr_part_number' => 'Q4172',
            'raw_description' => 'WIN USA 9MM 115GR FMJ 50RD',
        ]);

        $a = $this->matcher->matchProduct($first);
        $b = $this->matcher->matchProduct($second);

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, MasterAmmunition::count());
        $this->assertSame(1, $this->matcher->getStats()['created']);
        $this->assertSame(1, $this->matcher->getStats()['matched_mpn']);
    }

    /* ---------------------------------------------------------------- */
    /*  matchBatch */
    /* ---------------------------------------------------------------- */

    public function test_match_batch_links_unmatched_rows_and_returns_the_link_count(): void
    {
        $this->master(['upc' => '111111111111', 'mfr_part_number' => 'UPC1']);
        $this->product(['raw_upc' => '1 1111-1111 111', 'raw_description' => 'ignored']);
        $this->product(['raw_mfr_part_number' => 'H1', 'raw_description' => 'Winchester 9mm Luger 115gr FMJ 50rd']);
        $this->product(['raw_description' => 'assorted mixed brass, no headstamp']);

        $linked = $this->matcher->matchBatch(500);

        $this->assertSame(2, $linked);
        $this->assertSame(1, DistributorProduct::whereNull('master_ammunition_id')->count());
    }

    public function test_match_batch_honours_the_limit(): void
    {
        $this->seedAutoCreatable(5);

        $linked = $this->matcher->matchBatch(3);

        $this->assertSame(3, $linked);
        $this->assertSame(2, DistributorProduct::whereNull('master_ammunition_id')->count());
    }

    /* ---------------------------------------------------------------- */
    /*  Ingestion integration */
    /* ---------------------------------------------------------------- */

    public function test_feed_ingestion_links_every_upserted_product(): void
    {
        $mock = Mockery::mock(RsrFeedDriver::class.'[downloadFeed]');
        $mock->shouldReceive('downloadFeed')->andReturnUsing(function (): string {
            $tmp = tempnam(sys_get_temp_dir(), 'match_rsr_');
            copy(self::FIXTURE, $tmp);

            return $tmp;
        });
        $this->app->instance(RsrFeedDriver::class, $mock);

        $this->app->make(FeedIngestionService::class)->ingest($this->distributor);

        $this->assertSame(5, DistributorProduct::count());
        $this->assertSame(0, DistributorProduct::whereNull('master_ammunition_id')->count());
        $this->assertSame(5, MasterAmmunition::count());

        $ae = DistributorProduct::where('distributor_sku', 'RSR-AE223')->first();
        $this->assertNotNull($ae->master_ammunition_id);
        $this->assertSame('.223 Remington', $ae->masterAmmunition->caliber);
        $this->assertSame('Federal', $ae->masterAmmunition->manufacturer);
        $this->assertSame(55, $ae->masterAmmunition->bullet_weight_gr);
    }

    public function test_a_second_ingestion_does_not_rematch_already_linked_products(): void
    {
        $mock = Mockery::mock(RsrFeedDriver::class.'[downloadFeed]');
        $mock->shouldReceive('downloadFeed')->andReturnUsing(function (): string {
            $tmp = tempnam(sys_get_temp_dir(), 'match_rsr_');
            copy(self::FIXTURE, $tmp);

            return $tmp;
        });
        $this->app->instance(RsrFeedDriver::class, $mock);

        $service = $this->app->make(FeedIngestionService::class);
        $service->ingest($this->distributor);
        $service->ingest($this->distributor);

        $this->assertSame(5, MasterAmmunition::count());
    }

    /* ---------------------------------------------------------------- */
    /*  ammo:match command */
    /* ---------------------------------------------------------------- */

    public function test_command_links_all_unmatched_products_by_default(): void
    {
        $this->seedAutoCreatable(4);

        $this->artisan('ammo:match')
            ->expectsOutputToContain('Total processed')
            ->assertExitCode(0);

        $this->assertSame(0, DistributorProduct::whereNull('master_ammunition_id')->count());
        $this->assertSame(4, MasterAmmunition::count());
    }

    public function test_command_respects_the_limit_option(): void
    {
        $this->seedAutoCreatable(5);

        $this->artisan('ammo:match', ['--limit' => 2])->assertExitCode(0);

        $this->assertSame(3, DistributorProduct::whereNull('master_ammunition_id')->count());
    }

    public function test_command_all_option_processes_everything(): void
    {
        $this->seedAutoCreatable(6);

        $this->artisan('ammo:match', ['--all' => true])->assertExitCode(0);

        $this->assertSame(0, DistributorProduct::whereNull('master_ammunition_id')->count());
    }

    public function test_command_force_reevaluates_already_matched_rows(): void
    {
        $wrong = $this->master([
            'manufacturer' => 'Wrong Co',
            'mfr_part_number' => 'W1',
            'caliber' => 'Unknown',
            'upc' => null,
        ]);
        $right = $this->master([
            'manufacturer' => 'Federal',
            'mfr_part_number' => 'R1',
            'upc' => '222222222222',
        ]);
        $product = $this->product([
            'raw_upc' => '222222222222',
            'raw_description' => 'Federal 9mm 124gr',
            'master_ammunition_id' => $wrong->id,
        ]);

        $this->artisan('ammo:match')->assertExitCode(0);
        $this->assertSame($wrong->id, $product->fresh()->master_ammunition_id, 'default run skips linked rows');

        $this->artisan('ammo:match', ['--force' => true])->assertExitCode(0);
        $this->assertSame($right->id, $product->fresh()->master_ammunition_id, '--force re-evaluates and relinks');
    }

    public function test_command_is_a_noop_when_nothing_is_unmatched(): void
    {
        $this->artisan('ammo:match')->assertExitCode(0);
    }
}
