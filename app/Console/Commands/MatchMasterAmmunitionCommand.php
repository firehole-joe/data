<?php

namespace App\Console\Commands;

use App\Models\DistributorProduct;
use App\Services\Matching\ProductMatchingService;
use Illuminate\Console\Command;

class MatchMasterAmmunitionCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ammo:match
        {--limit=500 : Number of unmatched records to process}
        {--all : Process all unmatched records}
        {--force : Re-evaluate already matched records}';

    /**
     * @var string
     */
    protected $description = 'Link distributor products to canonical master ammunition records.';

    public function handle(ProductMatchingService $matcher): int
    {
        $all = (bool) $this->option('all');
        $force = (bool) $this->option('force');
        $limit = $all ? PHP_INT_MAX : max(1, (int) $this->option('limit'));

        $matcher->resetStats();

        $query = DistributorProduct::query()
            ->when(! $force, fn ($q) => $q->whereNull('master_ammunition_id'))
            ->orderBy('id');

        $available = (clone $query)->count();

        if ($available === 0) {
            $this->components->info($force
                ? 'No distributor products to evaluate.'
                : 'No unmatched distributor products.');

            return self::SUCCESS;
        }

        $target = min($available, $limit);
        $this->components->info(sprintf(
            'Evaluating %s of %s %s product%s%s.',
            number_format($target),
            number_format($available),
            $force ? 'total' : 'unmatched',
            $target === 1 ? '' : 's',
            $force ? ' (--force: re-evaluating matched rows)' : '',
        ));

        $processed = 0;
        $startedAt = microtime(true);

        $query->chunkById(ProductMatchingService::CHUNK_SIZE, function ($rows) use ($matcher, $limit, &$processed) {
            foreach ($rows as $product) {
                if ($processed >= $limit) {
                    return false;
                }

                $matcher->matchProduct($product);
                $processed++;
            }

            return $processed < $limit;
        });

        $stats = $matcher->getStats();
        $duration = number_format(microtime(true) - $startedAt, 2).'s';

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total processed', number_format($stats['processed'])],
                ['Matched via UPC', number_format($stats['matched_upc'])],
                ['Matched via MPN', number_format($stats['matched_mpn'])],
                ['Newly created master records', number_format($stats['created'])],
                ['Unmatched', number_format($stats['unmatched'])],
            ],
            'box',
        );

        $this->components->twoColumnDetail('<fg=gray>Duration</>', $duration);

        return self::SUCCESS;
    }
}
