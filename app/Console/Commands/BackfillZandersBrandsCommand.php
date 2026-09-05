<?php

namespace App\Console\Commands;

use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Matching\AmmunitionParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill the brand on `master_ammunition` rows that ingestion left as
 * "Unknown" because the description never named a brand the parser
 * recognised — overwhelmingly Zanders SKUs, whose feed carries an
 * explicit `MFG` column that the matcher historically ignored.
 *
 * For every "Unknown" master with at least one offering from the target
 * distributor, the brand is re-derived from that offering's
 * `raw_manufacturer` (the feed's own column) and, failing that, its
 * `raw_description`, via {@see AmmunitionParser::canonicalBrand()}. When
 * a real brand is found the master is renamed in place — unless doing so
 * would collide with an existing `(manufacturer, mfr_part_number)` row,
 * in which case the offerings are re-pointed at that canonical master and
 * the emptied "Unknown" record is deleted.
 */
class BackfillZandersBrandsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ammo:backfill-zanders-brands
        {--distributor=zanders : Distributor slug whose offerings drive the re-derivation}
        {--dry-run : Report what would change without writing}
        {--chunk=500 : Master rows processed per batch}';

    /**
     * @var string
     */
    protected $description = 'Re-derive the brand for master_ammunition rows stuck on "Unknown" from the distributor feed\'s manufacturer column and description prefix.';

    public function handle(AmmunitionParser $parser): int
    {
        $slug = trim((string) $this->option('distributor')) ?: 'zanders';
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $distributor = Distributor::where('slug', $slug)->first();

        if ($distributor === null) {
            $this->components->error("No distributor with slug [{$slug}].");

            return self::FAILURE;
        }

        $scoped = MasterAmmunition::query()
            ->where(fn ($q) => $q->whereIn('manufacturer', ['Unknown', ''])->orWhereNull('manufacturer'))
            ->whereHas('distributorProducts', fn ($q) => $q->where('distributor_id', $distributor->id))
            ->with(['distributorProducts' => fn ($q) => $q->where('distributor_id', $distributor->id)])
            ->orderBy('id');

        $total = (clone $scoped)->count();

        if ($total === 0) {
            $this->components->info("No \"Unknown\" master rows with {$slug} offerings to backfill.");

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Re-deriving brands for %s "Unknown" master row%s from %s offerings%s.',
            number_format($total),
            $total === 1 ? '' : 's',
            $slug,
            $dryRun ? ' (dry run — nothing will be written)' : '',
        ));

        $stats = ['scanned' => 0, 'updated' => 0, 'merged' => 0, 'unresolved' => 0];

        $scoped->chunkById($chunk, function ($masters) use ($parser, $dryRun, &$stats) {
            foreach ($masters as $master) {
                $stats['scanned']++;

                $brand = $this->deriveBrand($master, $parser);

                if ($brand === null || $this->sameBrand($brand, $master->manufacturer)) {
                    $stats['unresolved']++;

                    continue;
                }

                $collision = MasterAmmunition::query()
                    ->where('manufacturer', $brand)
                    ->where('mfr_part_number', $master->mfr_part_number)
                    ->where('id', '!=', $master->id)
                    ->first();

                if ($collision !== null) {
                    $this->line(sprintf(
                        '  master #%d "%s" %s → merge into #%d (%s %s)',
                        $master->id,
                        $master->name,
                        $master->mfr_part_number,
                        $collision->id,
                        $brand,
                        $collision->mfr_part_number,
                    ));

                    if (! $dryRun) {
                        $this->mergeInto($master, $collision);
                    }

                    $stats['merged']++;

                    continue;
                }

                $this->line(sprintf(
                    '  master #%d "%s": %s → %s',
                    $master->id,
                    $master->name,
                    $master->manufacturer ?: '(blank)',
                    $brand,
                ));

                if (! $dryRun) {
                    $master->forceFill(['manufacturer' => $brand])->save();

                    Log::channel('daily')->info('ammo.master.brand.backfilled', [
                        'master_ammunition_id' => $master->id,
                        'manufacturer' => $brand,
                    ]);
                }

                $stats['updated']++;
            }
        });

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Master rows scanned', number_format($stats['scanned'])],
            ['Brands renamed in place', number_format($stats['updated'])],
            ['Rows merged into an existing brand', number_format($stats['merged'])],
            ['Still unresolved (no brand in feed or description)', number_format($stats['unresolved'])],
        ], 'box');

        if ($dryRun) {
            $this->components->warn('Dry run: no changes were persisted.');
        } else {
            $this->components->info(sprintf(
                'Backfilled %s brand%s (%s merged).',
                number_format($stats['updated'] + $stats['merged']),
                ($stats['updated'] + $stats['merged']) === 1 ? '' : 's',
                number_format($stats['merged']),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * The first real brand any of the master's in-scope offerings yields,
     * feed manufacturer column first, description prefix second.
     */
    private function deriveBrand(MasterAmmunition $master, AmmunitionParser $parser): ?string
    {
        foreach ($master->distributorProducts as $offering) {
            $brand = $parser->canonicalBrand(
                $offering->raw_manufacturer,
                (string) $offering->raw_description,
            );

            if ($brand !== null) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * Re-point every offering on the "Unknown" master at the canonical
     * one and delete the emptied record.
     */
    private function mergeInto(MasterAmmunition $unknown, MasterAmmunition $canonical): void
    {
        DB::transaction(function () use ($unknown, $canonical) {
            DistributorProduct::where('master_ammunition_id', $unknown->id)
                ->update(['master_ammunition_id' => $canonical->id]);

            $unknown->delete();

            Log::channel('daily')->info('ammo.master.brand.merged', [
                'from_master_ammunition_id' => $unknown->id,
                'into_master_ammunition_id' => $canonical->id,
                'manufacturer' => $canonical->manufacturer,
            ]);
        });
    }

    private function sameBrand(string $brand, ?string $current): bool
    {
        return $current !== null && strcasecmp(trim($brand), trim($current)) === 0;
    }
}
