<?php

namespace App\Console\Commands;

use App\Models\Distributor;
use App\Services\Feeds\FeedIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyncDistributorFeedCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'feed:sync
        {slug? : The slug of the distributor to sync}
        {--all : Sync all active distributors}
        {--dry-run : Parse feed without persisting to database}';

    /**
     * @var string
     */
    protected $description = 'Download and ingest ammunition supply feeds from distributors.';

    public function handle(FeedIngestionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $distributors = $this->resolveDistributors();

        if ($distributors->isEmpty()) {
            $this->components->error('No matching distributor found.');
            $this->line('  Available: '.Distributor::orderBy('slug')->pluck('slug')->implode(', '));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Syncing %d distributor%s%s.',
            $distributors->count(),
            $distributors->count() === 1 ? '' : 's',
            $dryRun ? ' (dry run — nothing will be persisted)' : '',
        ));

        $rows = [];
        $totalSeconds = 0.0;
        $failures = 0;

        foreach ($distributors as $distributor) {
            $startedAt = microtime(true);
            $status = 'failed';
            $run = null;

            try {
                $run = $service->ingest($distributor, $dryRun);
                $status = $run->status;
            } catch (\Throwable $e) {
                Log::channel('daily')->error('feed:sync command failure', [
                    'distributor' => $distributor->slug,
                    'error' => $e->getMessage(),
                ]);
                $this->components->error("{$distributor->slug}: {$e->getMessage()}");
            }

            $elapsed = microtime(true) - $startedAt;
            $totalSeconds += $elapsed;

            if ($status !== 'completed') {
                $failures++;
                if ($run?->error_message) {
                    $this->components->error("{$distributor->slug}: {$run->error_message}");
                }
            }

            $rows[] = [
                $distributor->name,
                $distributor->slug,
                number_format((int) ($run?->rows_processed ?? 0)),
                number_format((int) ($run?->rows_updated ?? 0)),
                number_format((int) ($run?->rows_failed ?? 0)),
                $this->formatDuration($elapsed),
                $this->statusLabel($status),
            ];
        }

        $this->newLine();
        $this->table(
            ['Distributor', 'Slug', 'Processed', 'Updated', 'Failed', 'Duration', 'Status'],
            $rows,
            'box',
        );

        $this->components->twoColumnDetail('<fg=gray>Total duration</>', $this->formatDuration($totalSeconds));
        $this->components->twoColumnDetail(
            '<fg=gray>Result</>',
            $failures === 0
                ? '<fg=green;options=bold>all feeds synced</>'
                : "<fg=red;options=bold>{$failures} feed(s) failed</>",
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, Distributor>
     */
    private function resolveDistributors(): Collection
    {
        if ($this->option('all')) {
            return Distributor::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        $slug = $this->argument('slug');

        if ($slug === null || $slug === '') {
            $this->components->warn('Provide a distributor slug or pass --all.');

            return new Collection;
        }

        return Distributor::query()->where('slug', $slug)->get();
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return number_format($seconds, 2).'s';
        }

        $minutes = intdiv((int) $seconds, 60);
        $remainder = $seconds - ($minutes * 60);

        return "{$minutes}m ".number_format($remainder, 1).'s';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => '<fg=green;options=bold>completed</>',
            'running' => '<fg=yellow>running</>',
            'failed' => '<fg=red;options=bold>failed</>',
            default => $status,
        };
    }
}
