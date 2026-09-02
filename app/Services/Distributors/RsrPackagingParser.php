<?php

namespace App\Services\Distributors;

/**
 * Resolves the true per-listing round count from RSR-style inventory
 * descriptions.
 *
 *   - "box/case" slash notation — "MAGTECH 9MM 115GR FMJ 50/1000" is 50
 *     rounds per box, 1,000 per case. A standard box SKU must price
 *     against 50, not 1,000, or a $12.88 box computes as $0.013/round
 *     instead of ~$0.258/round.
 *   - a lone explicit count — "MAGTECH 9MM 115GR FMJ 1000RD CS" on a
 *     dedicated case SKU (MT9A-CSDP) is simply 1,000.
 *
 * {@see extractRoundCount()} returns null (rather than a magic "1") when
 * neither pattern is present, so the caller keeps its own caliber-family
 * fallback.
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
     * The round count implied by the description's packaging notation, or
     * null when neither slash notation nor an explicit count is present.
     *
     * Slash notation on a standard listing resolves to the box (first)
     * count; on a listing flagged as a case — by SKU marker or
     * description cue — it resolves to the case (second) count.
     */
    public static function extractRoundCount(string $description, string $sku = ''): ?int
    {
        $descUpper = strtoupper($description);

        // 1. "box/case" slash notation: two integers joined by a slash,
        //    not part of a longer number or a decimal ("5.56/223" is a
        //    caliber cross-ref, not packaging).
        if (preg_match('#(?<![\d.])(\d{1,4})\s*/\s*(\d{2,6})(?![\d.])#', $descUpper, $m)) {
            $boxQty = (int) $m[1];
            $caseQty = (int) $m[2];

            // A real case pack is the larger, round number.
            if ($boxQty >= 1 && $boxQty <= 500 && $caseQty > $boxQty && $caseQty % 10 === 0) {
                return self::isCaseListing($description, $sku) ? $caseQty : $boxQty;
            }
        }

        // 2. A lone explicit count: "1000RD CS", "50 BX", "500 PK".
        return self::explicitCount($descUpper);
    }

    /**
     * A lone "<n> RD/BX/PK/..." count, e.g. "1000RD CS", "50 BX",
     * "500 PK". Returns null when no such token is present.
     */
    private static function explicitCount(string $descUpper): ?int
    {
        if (preg_match('/(?<![\d.])(\d{1,4})\s*(RD|RDS|RND|RNDS|ROUND|ROUNDS|BX|BOX|PK|PACK|CT|COUNT)\b/', $descUpper, $m)) {
            $count = (int) $m[1];

            if ($count >= 1 && $count <= 5000) {
                return $count;
            }
        }

        return null;
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
