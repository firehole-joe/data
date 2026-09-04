<?php

namespace App\Console\Commands;

use App\Models\DistributorProduct;
use App\Services\Ammunition\AmmoAttributeExtractor;
use App\Services\Ammunition\MasterRoundCountReconciler;
use App\Services\Feeds\AmmoPricingGuardrail;
use App\Services\Feeds\DistributorSkuOverrideManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off repair for Davidson's offerings whose `round_count` was
 * inflated by the (now-fixed) `box_per_case` multiplication bug in
 * {@see \App\Services\Feeds\Drivers\DavidsonsFeedDriver}: a 50-round box
 * priced at $13.50, with `box_per_case = 10`, was stamped
 * `round_count = 500` — computing ~$0.027/rd and getting flagged as a
 * case-count-as-box-count parse.
 *
 * Re-derives the true count from what is already on file for the
 * offering — an explicit count stated in its own raw feed description
 * (Davidson's box copy almost always says "50RD"/"20RD" as plain text,
 * independent of the structured `round_per_box` column the driver
 * itself uses), falling back to the shared master's `rounds_per_box`
 * when that looks like a plausible retail box rather than a case. When
 * that true count is smaller than what is currently stored, it restores
 * `round_count`, recomputes `cost_per_round`, and clears `needs_review`
 * once the corrected $/rd is healthy again. A SKU a reviewer has already
 * ruled on via the override ledger — approved or ignored — is left
 * alone; that human decision always outranks this automated repair.
 */
class RepairDavidsonsRoundCountsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'davidsons:repair-round-counts
        {--dry-run : Report what would change without writing}';

    /**
     * @var string
     */
    protected $description = "Restore round_count for Davidson's offerings inflated by the box_per_case multiplication bug, clearing needs_review where the corrected price is healthy.";

    public function handle(
        AmmoAttributeExtractor $extractor,
        AmmoPricingGuardrail $guardrail,
        DistributorSkuOverrideManager $overrides,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        $offerings = DistributorProduct::query()
            ->whereHas('distributor', fn ($q) => $q->where('slug', 'davidsons'))
            ->where('needs_review', true)
            ->where('is_ignored', false)
            ->where('round_count', '>', 50)
            ->with('masterAmmunition')
            ->orderBy('id')
            ->get();

        $repaired = 0;
        $cleared = 0;
        $skipped = 0;

        foreach ($offerings as $offering) {
            // A reviewer has already ruled on this SKU (or its UPC) —
            // never silently overturn that decision.
            if ($overrides->resolve($offering)['matched']) {
                $skipped++;

                continue;
            }

            $trueCount = $this->trueRoundCount($offering, $extractor);

            // Only repair when the true count is smaller than what is
            // currently stored — i.e. it really does look inflated.
            if ($trueCount === null || $trueCount >= (int) $offering->round_count) {
                $skipped++;

                continue;
            }

            $price = (float) $offering->wholesale_price;
            $cpr = $price > 0 ? round($price / $trueCount, 4) : null;
            $caliber = $offering->masterAmmunition?->caliber
                ?? $extractor->extractCaliber((string) $offering->raw_description);
            $check = $guardrail->validate($price, $trueCount, $caliber);

            $this->line(sprintf(
                '  #%d %s: round_count %d -> %d, $/rd %s -> %s%s',
                $offering->id,
                $offering->distributor_sku,
                $offering->round_count,
                $trueCount,
                $offering->cost_per_round !== null ? '$'.number_format((float) $offering->cost_per_round, 4) : '—',
                $cpr !== null ? '$'.number_format($cpr, 4) : '—',
                $check['is_valid'] ? ' (cleared)' : ' (still flagged: '.$check['reason'].')',
            ));

            if (! $dryRun) {
                $offering->round_count = $trueCount;

                if ($cpr !== null) {
                    $offering->cost_per_round = $cpr;
                }

                if ($check['is_valid']) {
                    $offering->needs_review = false;
                    $offering->review_reason = null;
                }

                $offering->save();

                Log::channel('daily')->info('davidsons.round_count.repaired', [
                    'distributor_product_id' => $offering->id,
                    'distributor_sku' => $offering->distributor_sku,
                    'round_count' => $trueCount,
                    'cost_per_round' => $cpr,
                    'cleared' => $check['is_valid'],
                ]);
            }

            $repaired++;
            if ($check['is_valid']) {
                $cleared++;
            }
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Offerings repaired', number_format($repaired)],
            ['Review flags cleared', number_format($cleared)],
            ['Skipped (ledgered, or no smaller count on file)', number_format($skipped)],
        ], 'box');

        if ($dryRun) {
            $this->components->warn('Dry run: no changes were persisted.');
        } else {
            $this->components->info("Repaired {$repaired} Davidson's offering(s).");
        }

        return self::SUCCESS;
    }

    /**
     * The best available "true" round count for this offering: an
     * explicit count stated in its own raw feed description, or,
     * failing that, its shared master's `rounds_per_box` when that
     * itself looks like a plausible retail box rather than a case.
     */
    private function trueRoundCount(DistributorProduct $offering, AmmoAttributeExtractor $extractor): ?int
    {
        $attributes = $extractor->extract(
            (string) $offering->raw_description,
            null,
            (string) $offering->distributor_sku,
        );

        if ($attributes['round_count_explicit'] && $attributes['round_count'] > 0) {
            return (int) $attributes['round_count'];
        }

        $masterCount = (int) ($offering->masterAmmunition?->rounds_per_box ?? 0);

        return ($masterCount > 0 && $masterCount < MasterRoundCountReconciler::CASE_PACK_THRESHOLD)
            ? $masterCount
            : null;
    }
}
