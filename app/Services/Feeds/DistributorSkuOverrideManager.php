<?php

namespace App\Services\Feeds;

use App\Models\DistributorProduct;
use App\Models\DistributorSkuOverride;
use Illuminate\Support\Collection;

/**
 * Reads and writes the {@see DistributorSkuOverride} review ledger.
 *
 * The ledger is the durable memory of the review queue: once a reviewer
 * approves or ignores a listing, {@see resolve()} re-applies that
 * decision on every subsequent feed import — matched by distributor SKU
 * first, then by UPC — so a corrected or dismissed listing does not have
 * to be triaged again. A decision is only reconsidered when the listing's
 * price or packaging has drifted materially from the snapshot taken when
 * the decision was made ({@see materialChange()}).
 */
class DistributorSkuOverrideManager
{
    /** Wholesale-price drift (fraction) that resurfaces an approved listing. */
    public const PRICE_DRIFT_THRESHOLD = 0.15;

    /**
     * The ledger decision for an incoming offering.
     *
     * @return array{
     *     matched: bool,
     *     ignored: bool,
     *     round_count: ?int,
     *     resurface: bool,
     *     reason: ?string
     * }
     */
    public function resolve(DistributorProduct $product): array
    {
        $override = $this->findFor($product);

        if ($override === null) {
            return $this->decision(false, false, null, false, null);
        }

        if ($override->is_ignored) {
            return $this->decision(true, true, null, false, null);
        }

        $drift = $this->materialChange($product, $override);

        return $this->decision(
            matched: true,
            ignored: false,
            roundCount: (int) $override->round_count ?: null,
            resurface: $drift !== null,
            reason: $drift,
        );
    }

    /**
     * Record (or update) an "ignore" decision for a listing.
     */
    public function recordIgnored(DistributorProduct $product): DistributorSkuOverride
    {
        return $this->write($product, isIgnored: true, roundCount: null);
    }

    /**
     * Record (or update) an "approve at N rounds/unit" decision.
     */
    public function recordApproved(DistributorProduct $product, int $roundCount): DistributorSkuOverride
    {
        return $this->write($product, isIgnored: false, roundCount: $roundCount);
    }

    /**
     * The ledger row governing this offering, if any: an exact
     * distributor + SKU match wins, otherwise a UPC match.
     */
    public function findFor(DistributorProduct $product): ?DistributorSkuOverride
    {
        $product->loadMissing('masterAmmunition');

        $sku = (string) $product->distributor_sku;
        $upcs = $this->upcCandidates($product);

        /** @var Collection<int, DistributorSkuOverride> $candidates */
        $candidates = DistributorSkuOverride::query()
            ->where(function ($q) use ($product, $sku, $upcs) {
                $q->where(function ($inner) use ($product, $sku) {
                    $inner->where('distributor_id', $product->distributor_id)
                        ->where('distributor_sku', $sku);
                });

                if ($upcs !== []) {
                    $q->orWhereIn('upc', $upcs);
                }
            })
            ->get();

        return $candidates->first(
            fn (DistributorSkuOverride $o) => (int) $o->distributor_id === (int) $product->distributor_id
                && (string) $o->distributor_sku === $sku
        ) ?? $candidates->first(
            fn (DistributorSkuOverride $o) => $o->upc !== null && in_array($o->upc, $upcs, true)
        );
    }

    /**
     * Whether the listing has drifted enough from the decision snapshot
     * to warrant another look; the human-readable reason when it has,
     * null when the saved decision should simply be re-applied.
     */
    public function materialChange(DistributorProduct $product, DistributorSkuOverride $override): ?string
    {
        $reasons = [];

        $baseline = $override->baseline_price !== null ? (float) $override->baseline_price : null;
        $current = (float) $product->wholesale_price;

        if ($baseline !== null && $baseline > 0.0 && $current > 0.0) {
            $delta = abs($current - $baseline) / $baseline;

            if ($delta > self::PRICE_DRIFT_THRESHOLD) {
                $reasons[] = sprintf(
                    'wholesale cost moved %.0f%% ($%.2f → $%.2f) since review',
                    $delta * 100,
                    $baseline,
                    $current,
                );
            }
        }

        $snapshot = $override->baseline_description;

        if (is_string($snapshot) && trim($snapshot) !== ''
            && $this->normalizeText((string) $product->raw_description) !== $this->normalizeText($snapshot)) {
            $reasons[] = 'feed description changed since review';
        }

        return $reasons === []
            ? null
            : 'Approved override needs re-check: '.implode('; ', $reasons).'.';
    }

    private function write(DistributorProduct $product, bool $isIgnored, ?int $roundCount): DistributorSkuOverride
    {
        $product->loadMissing('masterAmmunition');

        $override = DistributorSkuOverride::firstOrNew([
            'distributor_id' => $product->distributor_id,
            'distributor_sku' => (string) $product->distributor_sku,
        ]);

        $override->fill([
            'upc' => $this->upcCandidates($product)[0] ?? null,
            'is_ignored' => $isIgnored,
            'round_count' => $roundCount ?? (int) $override->round_count,
            'baseline_price' => (float) $product->wholesale_price,
            'baseline_description' => (string) $product->raw_description,
            'note' => $isIgnored
                ? 'Ignored from the supply dashboard review queue.'
                : 'Approved from the supply dashboard review queue.',
        ]);

        $override->save();

        return $override;
    }

    /**
     * Digit-only UPC candidates for this offering (its own raw UPC and
     * its mapped master's), most specific first, de-duplicated.
     *
     * @return array<int, string>
     */
    private function upcCandidates(DistributorProduct $product): array
    {
        $values = [
            $this->normalizeUpc($product->raw_upc),
            $this->normalizeUpc($product->masterAmmunition?->upc),
        ];

        return array_values(array_unique(array_filter($values)));
    }

    /**
     * Digits only, left-padded to 12; null when it cannot be a UPC.
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

        return strlen($digits) < 12 ? str_pad($digits, 12, '0', STR_PAD_LEFT) : $digits;
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower($text)) ?? strtolower($text));
    }

    /**
     * @return array{matched: bool, ignored: bool, round_count: ?int, resurface: bool, reason: ?string}
     */
    private function decision(bool $matched, bool $ignored, ?int $roundCount, bool $resurface, ?string $reason): array
    {
        return [
            'matched' => $matched,
            'ignored' => $ignored,
            'round_count' => $roundCount,
            'resurface' => $resurface,
            'reason' => $reason,
        ];
    }
}
