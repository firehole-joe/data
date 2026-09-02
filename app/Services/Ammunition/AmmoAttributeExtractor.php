<?php

namespace App\Services\Ammunition;

use App\Services\Distributors\RsrPackagingParser;

/**
 * Context-aware extraction of the structured ammunition attributes the
 * supply dashboard filters and aggregates on: canonical caliber,
 * projectile type, grain weight, round count per box and the derived
 * cost-per-round.
 *
 * Every method is pure. Unknown scalar values come back as null; round
 * count always resolves (an explicit count when the description carries
 * one, otherwise the conventional default for the detected caliber
 * family) so a cost-per-round can always be derived.
 *
 * Canonical labels are deliberately aligned with the values already
 * stored in `master_ammunition.caliber` / `.bullet_type` by
 * {@see \App\Services\Matching\AmmunitionParser} so the extractor can be
 * used to backfill the same columns without a second normalisation pass.
 */
class AmmoAttributeExtractor
{
    /**
     * Canonical caliber => detection pattern, evaluated in order. The
     * first match wins, so collision-prone entries (.300 BLK vs .308,
     * 5.56 vs .223, .357 Mag vs .357 Sig) are listed most-specific
     * first.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const CALIBER_PATTERNS = [
        ['.300 AAC Blackout', '/\b\.?300\s*(?:aac\s*)?(?:blackout|blk)\b|\b\.?300\s*aac\b/'],
        ['8.6 Blackout', '/\b8\.6\s*(?:blackout|blk)\b/'],
        ['6.5 Creedmoor', '/\b6\.5\s*(?:creedmoor|crdmr|cm)\b/'],
        ['5.56x45mm NATO', '/\b5\.56(?:\s*x\s*45)?(?:mm)?\b|\b5\.56\s*nato\b/'],
        ['.223 Remington', '/\b\.?223\s*(?:rem(?:ington)?)?\b/'],
        ['.308 Winchester', '/\b\.?308\s*(?:win(?:chester)?)?\b|\b7\.62\s*(?:x\s*51|nato)\b/'],
        ['.380 ACP', '/\b\.?380\s*(?:acp|auto)\b/'],
        ['.38 Special', '/\b\.?38\s*(?:spl|special|spc)\b/'],
        ['.357 Magnum', '/\b\.?357\s*(?:mag(?:num)?|rem\s*mag)\b/'],
        ['.40 S&W', '/\b\.?40\s*(?:s\s*&\s*w|sw|smith)\b|\b40\s*cal\b/'],
        ['10mm Auto', '/\b10\s*mm(?:\s*auto)?\b/'],
        ['9mm Luger', '/\b9\s*mm(?:\s*(?:luger|parabellum|para))?\b|\b9\s*x\s*19\b/'],
        ['.45 ACP', '/\b\.?45\s*(?:acp|auto)\b/'],
        ['.22 LR', '/\b\.?22\s*(?:lr|long\s*rifle)\b/'],
        ['12 Gauge', '/\b12\s*(?:ga|gauge)\b/'],
    ];

    /** Calibers whose box count conventionally defaults to 20. */
    private const RIFLE_CALIBERS = [
        '5.56x45mm NATO',
        '.223 Remington',
        '.308 Winchester',
        '.300 AAC Blackout',
        '8.6 Blackout',
        '6.5 Creedmoor',
    ];

    /** Calibers whose box count conventionally defaults to 25. */
    private const SHOTGUN_CALIBERS = [
        '12 Gauge',
    ];

    /**
     * Canonical projectile type => detection pattern, evaluated in order
     * (the compound acronyms before their substrings: JHP before HP,
     * TMJ/FMJ before the tip types, "solid copper" before a bare SP).
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const PROJECTILE_PATTERNS = [
        ['Monolithic Solid Copper', '/\bmonolithic\b|\bsolid\s*copper\b|\bcopper\s*solid\b|\ball[-\s]?copper\b|\blead[-\s]?free\s*solid\b|\bgmx\b|\bt?tsx\b|\blehigh\b/'],
        ['JHP', '/\bjhp\b|jacketed\s*hollow\s*points?\b/'],
        ['TMJ', '/\btmj\b|total\s*metal\s*jackets?\b/'],
        ['FMJ', '/\bfmj(?:-?bt)?\b|full\s*metal\s*jackets?\b/'],
        ['OTM', '/\botm\b|open\s*tip\s*match\b|\bbthp\s*match\b/'],
        ['Frangible', '/\bfrangible\b|\bfrang\b|\bsinterfire\b/'],
        ['Subsonic', '/\bsub-?sonic\b/'],
        ['Polymer Tip', '/\bpolymer[-\s]?tip\b|\bpoly[-\s]?tip\b|\bp-?tip\b|\bplastic[-\s]?tip\b|\beld[-\s]?(?:m|x)\b|\bv-?max\b|\bz-?max\b|\bballistic\s*tip\b|\bsst\b/'],
        ['SP', '/\bspbt\b|\bsp\b|\bsoft[-\s]?points?\b|core-?lokt\b|\bpsp\b/'],
        ['HP', '/\bjhc\b|\bhp\b|\bhollow[-\s]?points?\b|gold\s*dot\b|\bhst\b|\bxtp\b/'],
    ];

    /**
     * Extract the full structured attribute bag for a description.
     *
     * When a wholesale price is supplied the cost-per-round is derived
     * from it and the resolved round count. The distributor SKU, when
     * known, disambiguates "50/1000" box/case slash notation.
     *
     * @return array{
     *     caliber: ?string,
     *     projectile_type: ?string,
     *     grain_weight: ?int,
     *     round_count: int,
     *     round_count_explicit: bool,
     *     cost_per_round: ?float
     * }
     */
    public function extract(string $description, ?float $wholesalePrice = null, string $sku = ''): array
    {
        $caliber = $this->extractCaliber($description);
        $explicitCount = $this->extractExplicitRoundCount($description, $sku);
        $roundCount = $explicitCount ?? $this->defaultRoundCount($caliber);

        return [
            'caliber' => $caliber,
            'projectile_type' => $this->extractProjectileType($description),
            'grain_weight' => $this->extractGrainWeight($description),
            'round_count' => $roundCount,
            'round_count_explicit' => $explicitCount !== null,
            'cost_per_round' => $wholesalePrice !== null
                ? $this->costPerRound($wholesalePrice, $roundCount)
                : null,
        ];
    }

    public function extractCaliber(string $text): ?string
    {
        $haystack = $this->normalize($text);

        $isMakarov = (bool) preg_match('/\b9\s*x\s*18\b|makarov|9\s*mm\s*mak\b/', $haystack);
        $isSig = (bool) preg_match('/\b\.?357\s*sig\b/', $haystack);

        foreach (self::CALIBER_PATTERNS as [$canonical, $pattern]) {
            if ($canonical === '9mm Luger' && $isMakarov) {
                continue;
            }

            if ($canonical === '.357 Magnum' && $isSig) {
                continue;
            }

            if (preg_match($pattern, $haystack)) {
                return $canonical;
            }
        }

        return null;
    }

    public function extractProjectileType(string $text): ?string
    {
        $haystack = $this->normalize($text);

        foreach (self::PROJECTILE_PATTERNS as [$canonical, $pattern]) {
            if (preg_match($pattern, $haystack)) {
                return $canonical;
            }
        }

        return null;
    }

    public function extractGrainWeight(string $text): ?int
    {
        if (! preg_match('/(\d{1,4})\s*(?:gr|grn|grain|grains)\b/i', $this->normalize($text), $m)) {
            return null;
        }

        $weight = (int) $m[1];

        return ($weight > 0 && $weight <= 1000) ? $weight : null;
    }

    /**
     * Resolve a round/box count, falling back to the conventional
     * default for the caliber family when the description has none.
     */
    public function extractRoundCount(string $text, ?string $caliber = null, string $sku = ''): int
    {
        return $this->extractExplicitRoundCount($text, $sku) ?? $this->defaultRoundCount($caliber);
    }

    /**
     * Estimated cost per round. Null when the inputs cannot yield a
     * meaningful figure (non-positive price or count).
     */
    public function costPerRound(?float $wholesalePrice, ?int $roundCount): ?float
    {
        if ($wholesalePrice === null || $roundCount === null) {
            return null;
        }

        if ($wholesalePrice <= 0.0 || $roundCount <= 0) {
            return null;
        }

        return round($wholesalePrice / $roundCount, 4);
    }

    /**
     * The explicit round count stated in the description, or null.
     *
     * "Box/case" slash notation ("50/1000") is resolved first — and to
     * the box count for a standard listing — so the generic "N round"
     * match cannot latch onto the trailing case number.
     */
    private function extractExplicitRoundCount(string $text, string $sku = ''): ?int
    {
        $slashCount = RsrPackagingParser::extractRoundCount($text, $sku);
        if ($slashCount !== null) {
            return $slashCount;
        }

        $haystack = $this->normalize($text);
        $unit = '(?:rd|rds|rnd|rnds|round|rounds|bx|box|pk|pack|count|ct)';

        if (preg_match('/(\d{1,4})[\s-]*'.$unit.'\b/i', $haystack, $m)
            || preg_match('/\b(?:box|pack|case)\s*(?:of\s*)?(\d{1,4})\b/i', $haystack, $m)) {
            $count = (int) $m[1];

            if ($count > 0 && $count <= 5000) {
                return $count;
            }
        }

        return null;
    }

    private function defaultRoundCount(?string $caliber): int
    {
        if ($caliber !== null && in_array($caliber, self::SHOTGUN_CALIBERS, true)) {
            return 25;
        }

        if ($caliber !== null && in_array($caliber, self::RIFLE_CALIBERS, true)) {
            return 20;
        }

        return 50;
    }

    private function normalize(string $text): string
    {
        $text = strtolower($text);
        $text = str_replace(['×', '·', '–', '—'], ['x', '.', '-', '-'], $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
