<?php

namespace App\Services\Distributors;

/**
 * Resolves the true per-listing round count from the "box/case" slash
 * notation distributors (RSR in particular) bury in inventory
 * descriptions — e.g. "MAGTECH 9MM 115GR FMJ 50/1000" means 50 rounds
 * per box and 1,000 per case. A standard box listing must price against
 * 50, not 1,000, or a $12.88 box computes as $0.013/round instead of
 * ~$0.258/round.
 *
 * Adapted from the reference RSR logic: {@see extractRoundCount()}
 * returns null (rather than a magic "1") when the description carries no
 * slash-notation packaging hint, so the caller keeps its own
 * explicit-count / caliber-family fallback.
 */
class RsrPackagingParser
{
    /**
     * SKU fragments RSR uses to flag a case (rather than box) listing.
     *
     * @var array<int, string>
     */
    private const CASE_SKU_MARKERS = ['-CS', '-CSDP', 'CSDP', '-CASE'];

    /**
     * The round count implied by "box/case" slash notation (e.g.
     * "50/1000", "20 / 500", "100/5000RD"), or null when the description
     * has no such notation.
     *
     * A standard listing resolves to the box (first) count; a listing
     * flagged as a case — by SKU marker or description cue — resolves to
     * the case (second) count.
     */
    public static function extractRoundCount(string $description, string $sku = ''): ?int
    {
        $descUpper = strtoupper($description);

        // Two integers joined by a slash, optionally spaced, not part of a
        // longer number or a decimal ("5.56/223" is a caliber cross-ref,
        // not packaging).
        if (! preg_match('#(?<![\d.])(\d{1,4})\s*/\s*(\d{2,6})(?![\d.])#', $descUpper, $m)) {
            return null;
        }

        $boxQty = (int) $m[1];
        $caseQty = (int) $m[2];

        // A real case pack is the larger, round number. Anything else is
        // almost certainly not packaging notation.
        if ($boxQty < 1 || $boxQty > 500 || $caseQty <= $boxQty || $caseQty % 10 !== 0) {
            return null;
        }

        return self::isCaseListing($description, $sku) ? $caseQty : $boxQty;
    }

    /**
     * Whether this specific listing is the CASE pack rather than a box.
     */
    public static function isCaseListing(string $description, string $sku = ''): bool
    {
        $skuUpper = strtoupper($sku);

        foreach (self::CASE_SKU_MARKERS as $marker) {
            if (str_contains($skuUpper, $marker)) {
                return true;
            }
        }

        if ($skuUpper !== '' && str_ends_with($skuUpper, 'CS')) {
            return true;
        }

        // Description cues only — deliberately NOT a bare "CASE", which in
        // ammunition copy usually describes the cartridge case
        // ("BRASS CASE", "NICKEL CASE"), not the pack size.
        return (bool) preg_match(
            '#\bCASE OF\b|\bPER CASE\b|\bCS\b|\d\s*CS\b#',
            strtoupper($description),
        );
    }
}
