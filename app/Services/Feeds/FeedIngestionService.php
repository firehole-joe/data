<?php

namespace App\Services\Feeds;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\FeedRun;
use App\Models\PriceHistory;
use App\Services\Ammunition\AmmoAttributeExtractor;
use App\Services\Feeds\Contracts\FeedDriverInterface;
use App\Services\Feeds\DTOs\FeedItemDTO;
use App\Services\Matching\ProductMatchingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use RuntimeException;

/**
 * Orchestrates a single distributor feed synchronisation:
 * download -> parse -> upsert products -> record price history ->
 * bookkeeping on the {@see FeedRun}.
 */
class FeedIngestionService
{
    /** How many parsed rows are persisted per database transaction. */
    public const CHUNK_SIZE = 250;

    /** Product columns that constitute a "meaningful" change for counting. */
    private const TRACKED_COLUMNS = [
        'raw_upc',
        'raw_mfr_part_number',
        'raw_description',
        'wholesale_price',
        'map_price',
        'msrp_price',
        'quantity_available',
        'is_in_stock',
    ];

    public function __construct(
        private readonly ProductMatchingService $matcher,
        private readonly AmmoAttributeExtractor $extractor,
        private readonly AmmoPricingGuardrail $guardrail,
        private readonly DistributorSkuOverrideManager $overrides,
    ) {}

    public function ingest(Distributor $distributor, bool $dryRun = false): FeedRun
    {
        $log = Log::channel('daily');

        $run = FeedRun::create([
            'distributor_id' => $distributor->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $log->info('feed.ingest.start', [
            'distributor' => $distributor->slug,
            'feed_run_id' => $run->id,
            'dry_run' => $dryRun,
        ]);

        $filePath = null;
        $processed = 0;
        $updated = 0;
        $failed = 0;

        try {
            $driver = $this->resolveDriver($distributor);
            $filePath = $driver->downloadFeed($distributor);

            $items = LazyCollection::make(function () use ($driver, $filePath) {
                yield from $driver->parseFeed($filePath);
            });

            foreach ($items->chunk(self::CHUNK_SIZE) as $chunk) {
                $persist = function () use ($chunk, $distributor, $dryRun, &$processed, &$updated, &$failed, $log): void {
                    foreach ($chunk as $dto) {
                        $processed++;

                        if ($dryRun) {
                            continue;
                        }

                        try {
                            if ($this->persistItem($distributor, $dto)) {
                                $updated++;
                            }
                        } catch (\Throwable $e) {
                            $failed++;
                            $log->warning('feed.item.failed', [
                                'distributor' => $distributor->slug,
                                'distributor_sku' => $dto->distributor_sku,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                };

                $dryRun ? $persist() : DB::transaction($persist);
            }

            $run->update([
                'status' => 'completed',
                'rows_processed' => $processed,
                'rows_updated' => $updated,
                'rows_failed' => $failed,
                'finished_at' => now(),
            ]);

            if (! $dryRun) {
                $distributor->forceFill(['last_synced_at' => now()])->save();
            }

            $log->info('feed.ingest.complete', [
                'distributor' => $distributor->slug,
                'feed_run_id' => $run->id,
                'rows_processed' => $processed,
                'rows_updated' => $updated,
                'rows_failed' => $failed,
                'dry_run' => $dryRun,
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'rows_processed' => $processed,
                'rows_updated' => $updated,
                'rows_failed' => $failed,
                'error_message' => \Illuminate\Support\Str::limit($e->getMessage(), 2000),
                'finished_at' => now(),
            ]);

            $log->error('feed.ingest.failed', [
                'distributor' => $distributor->slug,
                'feed_run_id' => $run->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($filePath !== null && is_file($filePath)) {
                @unlink($filePath);
            }
        }

        return $run->refresh();
    }

    /**
     * Upsert the distributor product and append today's price-history row.
     *
     * @return bool Whether the product was created or had a tracked column change.
     */
    private function persistItem(Distributor $distributor, FeedItemDTO $dto): bool
    {
        $product = DistributorProduct::firstOrNew([
            'distributor_id' => $distributor->id,
            'distributor_sku' => $dto->distributor_sku,
        ]);

        $product->fill([
            'raw_upc' => $dto->raw_upc,
            'raw_mfr_part_number' => $dto->raw_mfr_part_number,
            'raw_description' => $dto->raw_description,
            'wholesale_price' => $dto->wholesale_price,
            'map_price' => $dto->map_price,
            'msrp_price' => $dto->msrp_price,
            'quantity_available' => $dto->quantity_available,
            'is_in_stock' => $dto->quantity_available > 0,
        ]);

        $isMeaningfulChange = ! $product->exists || $product->isDirty(self::TRACKED_COLUMNS);

        $product->last_feed_update_at = now();
        $product->save();

        $this->matchIngestedProduct($product);
        $this->applyPricingGuardrail($product);

        PriceHistory::updateOrCreate(
            [
                'distributor_product_id' => $product->id,
                'recorded_date' => now()->toDateString(),
            ],
            [
                'wholesale_price' => $dto->wholesale_price,
                'quantity_available' => $dto->quantity_available,
            ],
        );

        return $isMeaningfulChange;
    }

    /**
     * Route a freshly upserted product through the matcher. A matching
     * failure must never abort feed ingestion, so it is logged and
     * swallowed here rather than bubbling into the per-row counters.
     */
    private function matchIngestedProduct(DistributorProduct $product): void
    {
        if ($product->master_ammunition_id !== null) {
            return;
        }

        try {
            $this->matcher->matchProduct($product);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('feed.match.failed', [
                'distributor_product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply the durable review ledger and the pricing guardrail to the
     * freshly upserted offering:
     *
     *  - A ledgered "ignore" (matched by distributor SKU or by UPC)
     *    bypasses the quarantine outright — `is_ignored` is asserted and
     *    any review flag cleared.
     *  - A ledgered "approve at N rounds/unit" pins that count and, as
     *    long as the wholesale cost and packaging string have not drifted
     *    materially from the decision snapshot, is trusted without a
     *    second guardrail pass. A material drift re-quarantines it.
     *  - Otherwise the guardrail runs as normal: `needs_review` +
     *    `review_reason` when the computed $/round is out of band (or no
     *    round count could be parsed), a cleared flag when it now passes.
     *
     * Absent a ledger decision the resolved round count prefers, in
     * order: a count already pinned to the row, an explicit packaging
     * count in the description / SKU, the mapped master's rounds-per-box,
     * then the caliber-family default.
     */
    private function applyPricingGuardrail(DistributorProduct $product): void
    {
        $product->loadMissing('masterAmmunition');
        $master = $product->masterAmmunition;

        // Already dismissed on the row: keep it out of the queue without
        // disturbing the ledger.
        if ($product->is_ignored) {
            if ($product->needs_review || $product->review_reason !== null) {
                $product->forceFill(['needs_review' => false, 'review_reason' => null])->save();
            }

            return;
        }

        $decision = $this->overrides->resolve($product);

        if ($decision['ignored']) {
            $product->forceFill([
                'is_ignored' => true,
                'needs_review' => false,
                'review_reason' => null,
            ])->save();

            return;
        }

        $attributes = $this->extractor->extract(
            (string) $product->raw_description,
            (float) $product->wholesale_price,
            (string) $product->distributor_sku,
        );

        $approvedCount = $decision['round_count'];
        $caliber = $master?->caliber ?? $attributes['caliber'];

        // Approved, but the listing has drifted since sign-off — send it
        // back for a fresh look rather than trusting a stale correction.
        if ($approvedCount !== null && $decision['resurface']) {
            $product->forceFill([
                'round_count' => $approvedCount,
                'needs_review' => true,
                'review_reason' => $decision['reason'],
            ])->save();

            Log::channel('daily')->warning('ammo.override.drift', [
                'distributor_id' => $product->distributor_id,
                'distributor_product_id' => $product->id,
                'distributor_sku' => $product->distributor_sku,
                'reason' => $decision['reason'],
            ]);

            return;
        }

        $roundCount = (int) ($approvedCount
            ?? $product->round_count
            ?? ($attributes['round_count_explicit']
                ? $attributes['round_count']
                : (($master && (int) $master->rounds_per_box > 0)
                    ? (int) $master->rounds_per_box
                    : $attributes['round_count'])));

        if ($approvedCount !== null && (int) $product->round_count !== $approvedCount) {
            $product->round_count = $approvedCount;
        }

        $cpr = $this->extractor->costPerRound((float) $product->wholesale_price, $roundCount);
        if ($cpr !== null) {
            $product->cost_per_round = $cpr;
        }

        // A stable approved override is trusted outright: a human has
        // signed off and nothing material has moved.
        if ($approvedCount !== null) {
            $product->forceFill(['needs_review' => false, 'review_reason' => null])->save();

            return;
        }

        $check = $this->guardrail->validate((float) $product->wholesale_price, $roundCount, $caliber);

        if (! $check['is_valid']) {
            $product->forceFill([
                'needs_review' => true,
                'review_reason' => $check['reason'],
            ])->save();

            Log::channel('daily')->warning('ammo.pricing.out_of_band', [
                'distributor_id' => $product->distributor_id,
                'distributor_product_id' => $product->id,
                'distributor_sku' => $product->distributor_sku,
                'raw_description' => $product->raw_description,
                'caliber' => $caliber,
                'parsed_round_count' => $roundCount,
                'wholesale_price' => (float) $product->wholesale_price,
                'cost_per_round' => $check['cost_per_round'],
                'reason' => $check['reason'],
            ]);

            return;
        }

        if ($product->needs_review) {
            $product->forceFill(['needs_review' => false, 'review_reason' => null])->save();

            return;
        }

        if ($product->isDirty()) {
            $product->save();
        }
    }

    private function resolveDriver(Distributor $distributor): FeedDriverInterface
    {
        $class = $distributor->driver_class;

        if (! $class || ! class_exists($class)) {
            throw new RuntimeException(
                "Feed driver [{$class}] for distributor [{$distributor->slug}] could not be resolved."
            );
        }

        $driver = app($class);

        if (! $driver instanceof FeedDriverInterface) {
            throw new RuntimeException(
                "Feed driver [{$class}] must implement ".FeedDriverInterface::class.'.'
            );
        }

        return $driver;
    }
}
