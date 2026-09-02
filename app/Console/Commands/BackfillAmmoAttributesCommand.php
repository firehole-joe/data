<?php

namespace App\Console\Commands;

use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Ammunition\AmmoAttributeExtractor;
use Illuminate\Console\Command;

class BackfillAmmoAttributesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ammo:backfill-attributes
        {--dry-run : Report what would change without writing}
        {--force : Overwrite master attributes that are already populated}
        {--chunk=500 : Rows processed per batch}';

    /**
     * @var string
     */
    protected $description = 'Extract caliber / projectile / grain / round-count from raw descriptions and backfill the derived cost-per-round.';

    public function handle(AmmoAttributeExtractor $extractor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $chunk = max(1, (int) $this->option('chunk'));

        $total = DistributorProduct::query()->count();

        if ($total === 0) {
            $this->components->info('No distributor products to process.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Backfilling attributes for %s distributor product%s%s.',
            number_format($total),
            $total === 1 ? '' : 's',
            $dryRun ? ' (dry run — nothing will be written)' : '',
        ));

        $stats = [
            'processed' => 0,
            'cpr_set' => 0,
            'masters_enriched' => 0,
            'master_fields' => 0,
        ];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        DistributorProduct::query()
            ->with('masterAmmunition')
            ->orderBy('id')
            ->chunkById($chunk, function ($products) use ($extractor, $dryRun, $force, &$stats, $bar) {
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

                    if ($cpr !== null && $this->differs($product->cost_per_round, $cpr)) {
                        $stats['cpr_set']++;
                        if (! $dryRun) {
                            $product->forceFill(['cost_per_round' => $cpr])->save();
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
            ],
            'box',
        );

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
