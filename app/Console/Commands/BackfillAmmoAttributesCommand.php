<?php

namespace App\Console\Commands;

use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\AmmoAttributeExtractor;
use App\Services\Feeds\AmmoPricingGuardrail;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class BackfillAmmoAttributesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ammo:backfill-attributes
        {--dry-run : Report what would change without writing}
        {--force : Overwrite master attributes that are already populated}
        {--distributor= : Limit reprocessing to one distributor slug (e.g. rsr)}
        {--chunk=500 : Rows processed per batch}';

    /**
     * @var string
     */
    protected $description = 'Reprocess distributor offerings: re-extract caliber / projectile / grain / round-count, correct box-vs-case round counts, recompute cost-per-round, and run the pricing guardrail (flagging offerings whose $/round falls outside the caliber-tier sanity band for review).';

    public function handle(AmmoAttributeExtractor $extractor, AmmoPricingGuardrail $guardrail): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $chunk = max(1, (int) $this->option('chunk'));
        $slug = ($raw = trim((string) $this->option('distributor'))) !== '' ? $raw : null;

        $scope = fn (Builder $q): Builder => $q->when(
            $slug !== null,
            fn (Builder $inner) => $inner->whereHas('distributor', fn ($d) => $d->where('slug', $slug)),
        );

        $total = $scope(DistributorProduct::query())->count();

        if ($total === 0) {
            $this->components->info($slug !== null
                ? "No distributor products to process for [{$slug}]."
                : 'No distributor products to process.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Reprocessing %s distributor product%s%s%s.',
            number_format($total),
            $total === 1 ? '' : 's',
            $slug !== null ? " for [{$slug}]" : '',
            $dryRun ? ' (dry run — nothing will be written)' : '',
        ));

        $stats = [
            'processed' => 0,
            'cpr_set' => 0,
            'masters_enriched' => 0,
            'master_fields' => 0,
            'flagged' => 0,
            'cleared' => 0,
        ];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $scope(DistributorProduct::query())
            ->with(['masterAmmunition', 'distributor'])
            ->orderBy('id')
            ->chunkById($chunk, function ($products) use ($extractor, $guardrail, $dryRun, $force, &$stats, $bar) {
                foreach ($products as $product) {
                    $stats['processed']++;
                    $bar->advance();

                    $attributes = $extractor->extract(
                        (string) $product->raw_description,
                        (float) $product->wholesale_price,
                        (string) $product->distributor_sku,
                    );

                    $master = $product->masterAmmunition;
                    // An explicit packaging count from the description / SKU
                    // (e.g. "50/1000" box/case slash notation) is the most
                    // reliable signal and overrides a possibly stale master.
                    $roundCount = $attributes['round_count_explicit']
                        ? $attributes['round_count']
                        : (($master && (int) $master->rounds_per_box > 0)
                            ? (int) $master->rounds_per_box
                            : $attributes['round_count']);

                    $cpr = $extractor->costPerRound((float) $product->wholesale_price, $roundCount);
                    $caliber = $master?->caliber ?? $attributes['caliber'];

                    if ($cpr !== null && $this->differs($product->cost_per_round, $cpr)) {
                        $stats['cpr_set']++;
                        if (! $dryRun) {
                            $product->forceFill(['cost_per_round' => $cpr])->save();
                        }
                    }

                    // Pricing guardrail: hold offerings whose $/round lands
                    // outside the caliber-tier sanity band (or that have no
                    // usable round count) out of the market averages until
                    // a human confirms or corrects them.
                    $check = $guardrail->validate((float) $product->wholesale_price, $roundCount, $caliber);

                    if (! $check['is_valid']) {
                        $stats['flagged']++;
                        if (! $dryRun && (! $product->needs_review || $product->review_reason !== $check['reason'])) {
                            $product->forceFill([
                                'needs_review' => true,
                                'review_reason' => $check['reason'],
                            ])->save();
                        }
                        Log::channel('daily')->warning('ammo.pricing.out_of_band', [
                            'distributor' => $product->distributor?->slug,
                            'distributor_product_id' => $product->id,
                            'distributor_sku' => $product->distributor_sku,
                            'raw_description' => $product->raw_description,
                            'caliber' => $caliber,
                            'parsed_round_count' => $roundCount,
                            'wholesale_price' => (float) $product->wholesale_price,
                            'cost_per_round' => $check['cost_per_round'],
                            'reason' => $check['reason'],
                        ]);
                    } elseif ($product->needs_review) {
                        $stats['cleared']++;
                        if (! $dryRun) {
                            $product->forceFill(['needs_review' => false, 'review_reason' => null])->save();
                        }
                    }

                    if ($master) {
                        $changed = $this->enrichMaster($master, $attributes, $force, $dryRun);
                        if ($changed > 0) {
                            $stats['masters_enriched']++;
                            $stats['master_fields'] += $changed;
                        }
                    }
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Products processed', number_format($stats['processed'])],
                ['Cost-per-round values written', number_format($stats['cpr_set'])],
                ['Master records enriched', number_format($stats['masters_enriched'])],
                ['Master fields filled', number_format($stats['master_fields'])],
                ['Flagged for review (price out of band)', number_format($stats['flagged'])],
                ['Review flags cleared', number_format($stats['cleared'])],
            ],
            'box',
        );

        if ($stats['flagged'] > 0) {
            $this->components->warn(sprintf(
                '%s offering%s outside the pricing sanity band — see the "ammo.pricing.out_of_band" log entries and the needs_review queue.',
                number_format($stats['flagged']),
                $stats['flagged'] === 1 ? '' : 's',
            ));
        }

        if ($dryRun) {
            $this->components->warn('Dry run: no changes were persisted.');
        }

        return self::SUCCESS;
    }

    /**
     * Fill blank (or, with --force, all) canonical attributes on the
     * master record from the extracted bag. Returns the field count set.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function enrichMaster(MasterAmmunition $master, array $attributes, bool $force, bool $dryRun): int
    {
        $updates = [];

        if ($attributes['caliber'] !== null && ($force || $this->blank($master->caliber))) {
            $updates['caliber'] = $attributes['caliber'];
        }

        if ($attributes['projectile_type'] !== null && ($force || $this->blank($master->bullet_type))) {
            $updates['bullet_type'] = $attributes['projectile_type'];
        }

        if ($attributes['grain_weight'] !== null && ($force || $master->bullet_weight_gr === null)) {
            $updates['bullet_weight_gr'] = $attributes['grain_weight'];
        }

        // An explicit description/SKU packaging count always wins — this is
        // what corrects masters that were stored with a case count (1000)
        // instead of the box count (50) before slash notation was handled.
        if ($attributes['round_count_explicit'] && ($force || (int) $master->rounds_per_box !== (int) $attributes['round_count'])) {
            $updates['rounds_per_box'] = $attributes['round_count'];
        }

        $updates = array_filter(
            $updates,
            fn ($value, $key) => (string) $master->{$key} !== (string) $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($updates === []) {
            return 0;
        }

        if (! $dryRun) {
            $master->forceFill($updates)->save();
        }

        return count($updates);
    }

    private function blank(?string $value): bool
    {
        return $value === null || $value === '' || strcasecmp($value, 'Unknown') === 0;
    }

    private function differs($current, float $next): bool
    {
        return $current === null || abs((float) $current - $next) >= 0.00005;
    }
}
