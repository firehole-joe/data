<?php

namespace App\Services\Matching;

use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Links {@see DistributorProduct} rows to a canonical
 * {@see MasterAmmunition} record.
 *
 * Matching hierarchy:
 *   1. exact UPC
 *   2. [manufacturer, mfr_part_number] (manufacturer from the feed's own
 *      brand column when it has one, else parsed from the description;
 *      part number taken from the raw feed value)
 *   3. auto-create from the parsed description (optional)
 */
class ProductMatchingService
{
    /** How many unlinked rows to pull per page in {@see matchBatch()}. */
    public const CHUNK_SIZE = 250;

    /**
     * Running tally for the current run, keyed:
     * processed, matched_upc, matched_mpn, created, unmatched.
     *
     * @var array<string, int>
     */
    private array $stats = [
        'processed' => 0,
        'matched_upc' => 0,
        'matched_mpn' => 0,
        'created' => 0,
        'unmatched' => 0,
    ];

    public function __construct(private readonly AmmunitionParser $parser) {}

    /**
     * Resolve (and optionally create) the MasterAmmunition for a product,
     * persisting the association when one is found.
     */
    public function matchProduct(DistributorProduct $product, bool $autoCreate = true): ?MasterAmmunition
    {
        $this->stats['processed']++;

        // Tier 1 — exact UPC.
        if ($upc = $this->normalizeUpc($product->raw_upc)) {
            $master = MasterAmmunition::whereIn('upc', $this->upcCandidates($upc))->first();

            if ($master) {
                $this->stats['matched_upc']++;

                return $this->associate($product, $master);
            }
        }

        $parsed = $this->parser->parse((string) $product->raw_description, (string) $product->distributor_sku);

        // Prefer the feed's own brand column over a description guess:
        // "Unknown" masters are overwhelmingly rows whose description
        // never named a brand the parser recognises.
        $manufacturer = $this->parser->canonicalBrand(
            $product->raw_manufacturer,
            (string) $product->raw_description,
        );
        $partNumber = $this->cleanString($product->raw_mfr_part_number);

        // Tier 2 — [manufacturer, mfr_part_number].
        if ($manufacturer !== null && $partNumber !== null) {
            $master = MasterAmmunition::query()
                ->where('manufacturer', $manufacturer)
                ->where('mfr_part_number', $partNumber)
                ->first();

            if ($master) {
                $this->stats['matched_mpn']++;

                return $this->associate($product, $master);
            }
        }

        // Tier 3 — auto-create from the parsed description.
        if ($autoCreate && $this->hasEnoughToCreate($parsed, $manufacturer, $partNumber)) {
            $master = $this->createMaster($product, $parsed, $manufacturer, $partNumber);

            $this->stats[$master->wasRecentlyCreated ? 'created' : 'matched_mpn']++;

            return $this->associate($product, $master);
        }

        $this->stats['unmatched']++;

        return null;
    }

    /**
     * Match unlinked products in id-ordered chunks.
     *
     * @return int The number of products that were linked to a master.
     */
    public function matchBatch(int $limit = 500): int
    {
        $linked = 0;
        $processed = 0;
        $seen = [];

        while ($processed < $limit) {
            $rows = DistributorProduct::query()
                ->whereNull('master_ammunition_id')
                ->when($seen !== [], fn ($q) => $q->whereNotIn('id', $seen))
                ->orderBy('id')
                ->limit(min(self::CHUNK_SIZE, $limit - $processed))
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $product) {
                if ($this->matchProduct($product) !== null) {
                    $linked++;
                }

                $seen[] = $product->id;
                $processed++;
            }
        }

        return $linked;
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = [
            'processed' => 0,
            'matched_upc' => 0,
            'matched_mpn' => 0,
            'created' => 0,
            'unmatched' => 0,
        ];
    }

    private function associate(DistributorProduct $product, MasterAmmunition $master): MasterAmmunition
    {
        if ($product->master_ammunition_id !== $master->id) {
            $product->master_ammunition_id = $master->id;
            $product->save();
        }

        return $master;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function hasEnoughToCreate(array $parsed, ?string $manufacturer, ?string $partNumber): bool
    {
        return $parsed['caliber'] !== null
            || ($manufacturer !== null && $partNumber !== null);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function createMaster(
        DistributorProduct $product,
        array $parsed,
        ?string $manufacturer,
        ?string $partNumber,
    ): MasterAmmunition {
        $manufacturer ??= 'Unknown';
        $partNumber ??= $this->syntheticPartNumber($product);
        $caliber = $parsed['caliber'] ?? 'Unknown';

        try {
            return MasterAmmunition::firstOrCreate(
                [
                    'manufacturer' => $manufacturer,
                    'mfr_part_number' => $partNumber,
                ],
                [
                    'upc' => $this->normalizeUpc($product->raw_upc),
                    'name' => $this->canonicalName($product, $parsed, $manufacturer, $caliber),
                    'caliber' => $caliber,
                    'bullet_weight_gr' => $parsed['bullet_weight_gr'],
                    'bullet_type' => $parsed['bullet_type'],
                    'rounds_per_box' => $parsed['rounds_per_box'],
                ],
            );
        } catch (\Throwable $e) {
            // A concurrent writer won the unique key: fall back to a read.
            Log::channel('daily')->warning('matching.create.race', [
                'distributor_product_id' => $product->id,
                'manufacturer' => $manufacturer,
                'mfr_part_number' => $partNumber,
                'error' => $e->getMessage(),
            ]);

            return MasterAmmunition::query()
                ->where('manufacturer', $manufacturer)
                ->where('mfr_part_number', $partNumber)
                ->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function canonicalName(
        DistributorProduct $product,
        array $parsed,
        string $manufacturer,
        string $caliber,
    ): string {
        $parts = array_filter([
            $manufacturer !== 'Unknown' ? $manufacturer : null,
            $caliber !== 'Unknown' ? $caliber : null,
            $parsed['bullet_weight_gr'] ? $parsed['bullet_weight_gr'].'gr' : null,
            $parsed['bullet_type'],
        ]);

        $name = trim(implode(' ', $parts));

        if ($name === '') {
            $name = trim((string) $product->raw_description);
        }

        return Str::limit($name, 250, '') ?: 'Unknown Ammunition';
    }

    private function syntheticPartNumber(DistributorProduct $product): string
    {
        $seed = $product->raw_upc ?: $product->distributor_sku ?: (string) $product->id;

        return 'AUTO-'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $seed) ?: (string) $product->id);
    }

    /**
     * Digits-only UPC, left-padded to 12; null when it cannot be a UPC.
     */
    private function normalizeUpc(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '' || (int) $digits === 0 || strlen($digits) > 14 || strlen($digits) < 11) {
            return null;
        }

        if (strlen($digits) < 12) {
            $digits = str_pad($digits, 12, '0', STR_PAD_LEFT);
        }

        return $digits;
    }

    /**
     * @return array<int, string>
     */
    private function upcCandidates(string $upc): array
    {
        return array_values(array_unique([
            $upc,
            ltrim($upc, '0') ?: $upc,
            str_pad($upc, 12, '0', STR_PAD_LEFT),
            str_pad($upc, 13, '0', STR_PAD_LEFT),
            str_pad($upc, 14, '0', STR_PAD_LEFT),
        ]));
    }

    private function cleanString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
