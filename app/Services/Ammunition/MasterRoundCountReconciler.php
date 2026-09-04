<?php

namespace App\Services\Ammunition;

use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Services\Feeds\AmmoPricingGuardrail;

/**
 * Keeps `master_ammunition.rounds_per_box` honest when a case-pack SKU
 * (e.g. `MT9A-CSDP`, a 1000-round case at ~$258) shares a UPC with the
 * 50-round retail boxes.
 *
 * Left unchecked, ingesting the case SKU pins the master to 1000, and
 * every 50-count box that falls back to the master's count then divides
 * its ~$13 wholesale by 1000 → ~$0.013/rd, poisoning the dashboard's
 * lowest-$/round figures.
 *
 * The reconciler:
 *  - identifies case packs from the SKU / description / price;
 *  - derives an independent per-offering round count that never trusts a
 *    case-sized master count for a standard box; and
 *  - corrects a master that has been pinned to a case count back down to
 *    the standard box count its own offerings actually describe
 *    (centerfire pistol / rifle only, and only downward).
 */
class MasterRoundCountReconciler
{
    /** Counts at or above this are case / bulk packs, not standard boxes. */
    public const CASE_PACK_THRESHOLD = 200;

    public function __construct(
        private readonly AmmoAttributeExtractor $extractor,
        private readonly AmmoPricingGuardrail $guardrail,
    ) {}

    /**
     * Is this listing a case / bulk pack rather than a retail box?
     */
    public function isCasePack(string $sku, string $description, float $price, ?string $caliber): bool
    {
        $sku = strtolower(trim($sku));
        $text = strtolower(trim($description));
        $blob = $sku.' '.$text;

        // Explicit "case" markers. NB: "50/1000" box/case slash notation
        // is NOT a case marker — it is a standard retail box drawn from a
        // 1000-round case; the extractor resolves it to the box count.
        if (preg_match('/\b(csdp|case|cased)\b/', $blob)
            || preg_match('/(?:^|[^a-z0-9])cs(?:dp)?(?:$|[^a-z0-9])/', $sku)
            || preg_match('/-cs$/', $sku)) {
            return true;
        }

        // A bare large round count with no leading box number
        // ("1000RD", "500 rounds") — a case sold as one unit.
        if (preg_match('/(?<![\d\/])\b(\d{3,4})\s*(?:rd|rds|rnd|rnds|round|rounds|ct|count)\b/', $blob, $m)
            && (int) $m[1] >= self::CASE_PACK_THRESHOLD) {
            return true;
        }

        // Price alone: a centerfire pistol / rifle retail box practically
        // never wholesales above $150.
        if ($price > 150.0 && $this->isCenterfire($caliber)) {
            return true;
        }

        return false;
    }

    /**
     * The independent rounds-per-unit for an offering, with a
     * `confident` flag saying whether the count came from a hard signal
     * (a reviewer override, or an explicit packaging count) rather than
     * a fallback.
     *
     * Priority: reviewer override → count already pinned to the row →
     * explicit packaging count in the description / SKU → the master's
     * `rounds_per_box` (kept even when case-sized so the pricing
     * guardrail can catch a poisoned master) → caliber-family default.
     *
     * @return array{count: int, confident: bool}
     */
    public function resolveRoundCount(
        DistributorProduct $offering,
        ?MasterAmmunition $master,
        ?int $approvedCount = null,
    ): array {
        if ($approvedCount !== null && $approvedCount > 0) {
            return ['count' => $approvedCount, 'confident' => true];
        }

        if ($offering->round_count !== null && (int) $offering->round_count > 0) {
            return ['count' => (int) $offering->round_count, 'confident' => true];
        }

        $caliber = $master?->caliber ?? $this->extractor->extractCaliber((string) $offering->raw_description);
        $isCase = $this->isCasePack(
            (string) $offering->distributor_sku,
            (string) $offering->raw_description,
            (float) $offering->wholesale_price,
            $caliber,
        );

        $attributes = $this->extractor->extract(
            (string) $offering->raw_description,
            null,
            (string) $offering->distributor_sku,
        );

        if ($attributes['round_count_explicit'] && $attributes['round_count'] > 0) {
            return ['count' => (int) $attributes['round_count'], 'confident' => true];
        }

        // A case pack with no explicit count: the caliber default beats a
        // stale master.
        if ($isCase) {
            return ['count' => (int) $attributes['round_count'], 'confident' => false];
        }

        $masterCount = (int) ($master?->rounds_per_box ?? 0);
        if ($masterCount > 0) {
            // Trust the master even when it looks case-sized — a wrong
            // value here is exactly what the guardrail is meant to flag.
            return ['count' => $masterCount, 'confident' => false];
        }

        return ['count' => (int) $attributes['round_count'], 'confident' => false];
    }

    /**
     * Re-derive `rounds_per_box` for a master from its own non-ignored,
     * non-case offerings and, when the master is currently pinned to a
     * case-sized count for a centerfire pistol / rifle caliber, correct
     * it downward to the box count those offerings agree on.
     *
     * @param  bool  $persist  Write the correction (false only previews it).
     * @return int|null the corrected count when it changed, otherwise null
     */
    public function reconcile(?MasterAmmunition $master, bool $persist = true): ?int
    {
        if ($master === null || ! $this->isCenterfire($master->caliber)) {
            return null;
        }

        $current = (int) $master->rounds_per_box;
        if ($current < self::CASE_PACK_THRESHOLD) {
            return null;
        }

        $offerings = DistributorProduct::query()
            ->where('master_ammunition_id', $master->id)
            ->where('is_ignored', false)
            ->get(['id', 'distributor_sku', 'raw_description', 'round_count', 'wholesale_price']);

        $votes = [];
        foreach ($offerings as $offering) {
            $box = $this->standardBoxCount($offering, $master->caliber);
            if ($box !== null) {
                $votes[] = $box;
            }
        }

        if ($votes === []) {
            return null;
        }

        $consensus = $this->consensus($votes);

        if ($consensus <= 0 || $consensus >= $current) {
            return null;
        }

        if ($persist) {
            $master->forceFill(['rounds_per_box' => $consensus])->save();
        }

        return $consensus;
    }

    /**
     * The credible standard-box count an offering votes for, or null when
     * it is a case pack or carries no usable box signal.
     */
    public function standardBoxCount(DistributorProduct $offering, ?string $caliber): ?int
    {
        $isCase = $this->isCasePack(
            (string) $offering->distributor_sku,
            (string) $offering->raw_description,
            (float) $offering->wholesale_price,
            $caliber,
        );

        if ($isCase) {
            return null;
        }

        $attributes = $this->extractor->extract(
            (string) $offering->raw_description,
            null,
            (string) $offering->distributor_sku,
        );

        if ($attributes['round_count_explicit']
            && $attributes['round_count'] > 0
            && $attributes['round_count'] < self::CASE_PACK_THRESHOLD) {
            return (int) $attributes['round_count'];
        }

        $pinned = (int) ($offering->round_count ?? 0);
        if ($pinned > 0 && $pinned < self::CASE_PACK_THRESHOLD) {
            return $pinned;
        }

        return null;
    }

    private function isCenterfire(?string $caliber): bool
    {
        return in_array(
            $this->guardrail->tierFor($caliber),
            [AmmoPricingGuardrail::TIER_HANDGUN, AmmoPricingGuardrail::TIER_RIFLE],
            true,
        );
    }

    /**
     * Most frequent vote; ties resolve to the smallest count.
     *
     * @param  array<int, int>  $votes
     */
    private function consensus(array $votes): int
    {
        $freq = array_count_values($votes);
        $max = max($freq);

        return (int) min(array_keys(array_filter($freq, fn ($count) => $count === $max)));
    }
}
