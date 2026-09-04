<?php

namespace App\Console\Commands;

use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\AmmoAttributeExtractor;
use App\Services\Ammunition\MasterRoundCountReconciler;
use App\Services\Feeds\AmmoPricingGuardrail;
use App\Services\Feeds\DistributorSkuOverrideManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sweep the catalog for offerings whose computed cost-per-round is
 * anomalously low — the tell-tale of a case count (500 / 1000) being
 * divided into a retail-box price — and repair them:
 *
 *  1. reconcile any master a case SKU has pinned to a case count back
 *     down to the box count its own offerings agree on;
 *  2. re-derive each offering's independent round count and recompute
 *     its cost-per-round;
 *  3. quarantine (`needs_review = true`) any centerfire pistol / rifle
 *     offering still below {@see AmmoPricingGuardrail::CENTERFIRE_HARD_FLOOR},
 *     and clear the flag on any that now price cleanly.
 */
class RecalculatePricingCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ammo:recalculate-pricing
        {--dry-run : Report what would change without writing}
        {--chunk=500 : Rows processed per batch}';

    /**
     * @var string
     */
    protected $description = 'Find and fix offerings whose cost-per-round is anomalously low (< $0.05 for centerfire) — usually a case count used as a box count.';

    public function handle(
        AmmoAttributeExtractor $extractor,
        AmmoPricingGuardrail $guardrail,
        DistributorSkuOverrideManager $overrides,
        MasterRoundCountReconciler $reconciler,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $stats = [
            'scanned' => 0,
            'masters_reconciled' => 0,
            'round_counts_pinned' => 0,
            'cpr_rewritten' => 0,
            'flagged' => 0,
            'cleared' => 0,
        ];

        // 1. Reconcile every centerfire master pinned to a case-sized count.
        $suspectMasters = MasterAmmunition::query()
            ->where('rounds_per_box', '>=', MasterRoundCountReconciler::CASE_PACK_THRESHOLD)
            ->get();

        foreach ($suspectMasters as $master) {
            $before = (int) $master->rounds_per_box;
            $to = $reconciler->reconcile($master, ! $dryRun);

            if ($to !== null && $to !== $before) {
                $stats['masters_reconciled']++;
                $this->line(sprintf(
                    '  master #%d (%s): rounds_per_box %d → %d',
                    $master->id,
                    $master->caliber,
                    $before,
                    $to,
                ));
            }
        }

        // 2. Recompute cost-per-round and re-run the guardrail per offering.
        DistributorProduct::query()
            ->where('is_ignored', false)
            ->whereNotNull('master_ammunition_id')
            ->with(['masterAmmunition', 'distributor'])
            ->orderBy('id')
            ->chunkById($chunk, function ($offerings) use (
                $extractor,
                $guardrail,
                $overrides,
                $reconciler,
                $dryRun,
                &$stats,
            ) {
                foreach ($offerings as $offering) {
                    $stats['scanned']++;

                    $master = $offering->masterAmmunition;
                    $decision = $overrides->resolve($offering);

                    if ($decision['ignored']) {
                        continue;
                    }

                    $approvedCount = $decision['round_count'];
                    $resolved = $reconciler->resolveRoundCount($offering, $master, $approvedCount);
                    $roundCount = $resolved['count'];
                    $caliber = $master?->caliber
                        ?? $extractor->extractCaliber((string) $offering->raw_description);

                    if ($resolved['confident'] && (int) $offering->round_count !== $roundCount) {
                        $stats['round_counts_pinned']++;
                        if (! $dryRun) {
                            $offering->round_count = $roundCount;
                        }
                    }

                    $cpr = $extractor->costPerRound((float) $offering->wholesale_price, $roundCount);
                    if ($cpr !== null && $this->differs($offering->cost_per_round, $cpr)) {
                        $stats['cpr_rewritten']++;
                        if (! $dryRun) {
                            $offering->cost_per_round = $cpr;
                        }
                    }

                    // An approved override is a human sign-off — trust it.
                    if ($approvedCount !== null) {
                        if (! $dryRun && ($offering->needs_review || $offering->review_reason !== null)) {
                            $stats['cleared']++;
                            $offering->needs_review = false;
                            $offering->review_reason = null;
                        }
                        if (! $dryRun && $offering->isDirty()) {
                            $offering->save();
                        }

                        continue;
                    }

                    $check = $guardrail->validate((float) $offering->wholesale_price, $roundCount, $caliber);

                    if (! $check['is_valid']) {
                        if (! $offering->needs_review || $offering->review_reason !== $check['reason']) {
                            $stats['flagged']++;
                            if (! $dryRun) {
                                $offering->needs_review = true;
                                $offering->review_reason = $check['reason'];
                            }
                        }
                        Log::channel('daily')->warning('ammo.pricing.recalculated_out_of_band', [
                            'distributor' => $offering->distributor?->slug,
                            'distributor_product_id' => $offering->id,
                            'distributor_sku' => $offering->distributor_sku,
                            'caliber' => $caliber,
                            'round_count' => $roundCount,
                            'wholesale_price' => (float) $offering->wholesale_price,
                            'cost_per_round' => $check['cost_per_round'],
                            'reason' => $check['reason'],
                        ]);
                    } elseif ($offering->needs_review) {
                        $stats['cleared']++;
                        if (! $dryRun) {
                            $offering->needs_review = false;
                            $offering->review_reason = null;
                        }
                    }

                    if (! $dryRun && $offering->isDirty()) {
                        $offering->save();
                    }
                }
            });

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Offerings scanned', number_format($stats['scanned'])],
            ['Master round counts reconciled (case → box)', number_format($stats['masters_reconciled'])],
            ['Per-offering round counts pinned', number_format($stats['round_counts_pinned'])],
            ['Cost-per-round values rewritten', number_format($stats['cpr_rewritten'])],
            ['Offerings newly flagged (below $0.05 / band)', number_format($stats['flagged'])],
            ['Review flags cleared', number_format($stats['cleared'])],
        ], 'box');

        if ($dryRun) {
            $this->components->warn('Dry run: no changes were persisted.');
        } elseif ($stats['flagged'] > 0) {
            $this->components->warn(sprintf(
                '%s offering%s still price below the sanity band — see the needs_review queue.',
                number_format($stats['flagged']),
                $stats['flagged'] === 1 ? '' : 's',
            ));
        } else {
            $this->components->info('Pricing looks clean — nothing anomalous left below the floor.');
        }

        return self::SUCCESS;
    }

    private function differs($current, float $next): bool
    {
        return $current === null || abs((float) $current - $next) >= 0.00005;
    }
}
