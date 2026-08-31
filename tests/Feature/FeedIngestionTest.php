<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\FeedRun;
use App\Models\PriceHistory;
use App\Services\Feeds\Drivers\RsrFeedDriver;
use App\Services\Feeds\FeedIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FeedIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/rsr_sample_feed.txt';

    protected function setUp(): void
    {
        parent::setUp();

        // Replace only the transport layer; the real parser still runs.
        $mock = Mockery::mock(RsrFeedDriver::class.'[downloadFeed]');
        $mock->shouldReceive('downloadFeed')->andReturnUsing(function (): string {
            $tmp = tempnam(sys_get_temp_dir(), 'test_rsr_');
            copy(self::FIXTURE, $tmp);

            return $tmp;
        });

        $this->app->instance(RsrFeedDriver::class, $mock);
    }

    private function makeDistributor(array $overrides = []): Distributor
    {
        return Distributor::create(array_merge([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'driver_class' => RsrFeedDriver::class,
            'transport_type' => 'sftp',
            'connection_settings' => ['host' => 'sftp.example.test', 'username' => 'u'],
        ], $overrides));
    }

    private function service(): FeedIngestionService
    {
        return $this->app->make(FeedIngestionService::class);
    }

    public function test_it_ingests_only_ammunition_rows_and_records_price_history(): void
    {
        $distributor = $this->makeDistributor();

        $run = $this->service()->ingest($distributor);

        $this->assertSame('completed', $run->status);
        $this->assertSame(5, $run->rows_processed);
        $this->assertSame(5, $run->rows_updated);
        $this->assertSame(0, $run->rows_failed);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $this->assertSame(5, DistributorProduct::count());
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'RSR-GLOCK19']);
        $this->assertDatabaseMissing('distributor_products', ['distributor_sku' => 'RSR-VORTEX']);

        $this->assertDatabaseHas('distributor_products', [
            'distributor_id' => $distributor->id,
            'distributor_sku' => 'RSR-AE223',
            'quantity_available' => 340,
            'is_in_stock' => true,
        ]);
        $this->assertDatabaseHas('distributor_products', [
            'distributor_sku' => 'RSR-CCI22',
            'quantity_available' => 0,
            'is_in_stock' => false,
        ]);

        $this->assertSame(
            '000008901234',
            DistributorProduct::where('distributor_sku', 'RSR-SHORTUPC')->value('raw_upc'),
        );

        $ae = DistributorProduct::where('distributor_sku', 'RSR-AE223')->first();
        $this->assertSame(5, PriceHistory::count());
        $this->assertDatabaseHas('price_histories', [
            'distributor_product_id' => $ae->id,
            'recorded_date' => now()->toDateString(),
            'quantity_available' => 340,
        ]);
        $this->assertEqualsWithDelta(7.5, (float) $ae->priceHistories()->first()->wholesale_price, 0.001);

        $this->assertNotNull($distributor->fresh()->last_synced_at);
    }

    public function test_a_second_identical_run_is_idempotent(): void
    {
        $distributor = $this->makeDistributor();

        $this->service()->ingest($distributor);
        $second = $this->service()->ingest($distributor);

        $this->assertSame('completed', $second->status);
        $this->assertSame(5, $second->rows_processed);
        $this->assertSame(0, $second->rows_updated, 'unchanged rows should not be counted as updated');
        $this->assertSame(0, $second->rows_failed, 'a repeat run must not raise per-row errors');
        $this->assertSame(5, DistributorProduct::count());
        $this->assertSame(5, PriceHistory::count(), 'same-day history is upserted, never duplicated');
        $this->assertSame(2, FeedRun::count());
    }

    public function test_price_and_stock_changes_are_counted_and_persisted(): void
    {
        $distributor = $this->makeDistributor();
        $this->service()->ingest($distributor);

        DistributorProduct::where('distributor_sku', 'RSR-AE223')->update([
            'wholesale_price' => 99.99,
            'quantity_available' => 0,
            'is_in_stock' => false,
        ]);

        $run = $this->service()->ingest($distributor);

        $this->assertSame(1, $run->rows_updated);
        $ae = DistributorProduct::where('distributor_sku', 'RSR-AE223')->first();
        $this->assertEqualsWithDelta(7.5, (float) $ae->wholesale_price, 0.001);
        $this->assertTrue((bool) $ae->is_in_stock);
    }

    public function test_dry_run_persists_nothing(): void
    {
        $distributor = $this->makeDistributor();

        $run = $this->service()->ingest($distributor, dryRun: true);

        $this->assertSame('completed', $run->status);
        $this->assertSame(5, $run->rows_processed);
        $this->assertSame(0, $run->rows_updated);
        $this->assertSame(0, DistributorProduct::count());
        $this->assertSame(0, PriceHistory::count());
        $this->assertNull($distributor->fresh()->last_synced_at);
    }

    public function test_ingest_records_a_failed_run_when_the_driver_cannot_be_resolved(): void
    {
        $distributor = $this->makeDistributor([
            'slug' => 'broken',
            'driver_class' => 'App\\Services\\Feeds\\Drivers\\MissingDriver',
        ]);

        $run = $this->service()->ingest($distributor);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('could not be resolved', (string) $run->error_message);
        $this->assertNull($distributor->fresh()->last_synced_at);
    }

    public function test_command_syncs_a_single_distributor_by_slug(): void
    {
        $this->makeDistributor();

        $this->artisan('feed:sync', ['slug' => 'rsr'])->assertExitCode(0);

        $this->assertSame(5, DistributorProduct::count());
        $this->assertDatabaseHas('feed_runs', ['status' => 'completed', 'rows_processed' => 5]);
    }

    public function test_command_dry_run_leaves_the_database_untouched(): void
    {
        $this->makeDistributor();

        $this->artisan('feed:sync', ['slug' => 'rsr', '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, DistributorProduct::count());
        $this->assertDatabaseHas('feed_runs', ['status' => 'completed', 'rows_processed' => 5]);
    }

    public function test_command_returns_failure_when_a_feed_fails(): void
    {
        $this->makeDistributor([
            'slug' => 'broken',
            'driver_class' => 'App\\Services\\Feeds\\Drivers\\MissingDriver',
        ]);

        $this->artisan('feed:sync', ['slug' => 'broken'])->assertExitCode(1);

        $this->assertDatabaseHas('feed_runs', ['status' => 'failed']);
    }

    public function test_command_errors_when_no_distributor_matches(): void
    {
        $this->artisan('feed:sync', ['slug' => 'nope'])->assertExitCode(1);
    }
}
