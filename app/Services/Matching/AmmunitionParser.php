<?php

namespace App\Services\Matching;

use App\Services\Distributors\RsrPackagingParser;

/**
 * Best-effort extraction of structured ammunition attributes from the
 * free-text descriptions distributors ship in their feeds.
 *
 * Every method is pure and side-effect free; unknown values come back as
 * null (except rounds-per-box, which always resolves to a sensible
 * default based on the detected caliber family).
 */
class AmmunitionParser
{
    /**
     * Canonical caliber => detection pattern, evaluated in order. The
     * first match wins, so collision-prone entries (e.g. .300 Blackout
     * vs .308, 5.56 vs .223) are listed most-specific first.
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
        ['9mm Luger', '/\b9\s*mm(?:\s*(?:luger|parabellum|para))?\b|\b9\s*x\s*19\b/'],
        ['.45 ACP', '/\b\.?45\s*(?:acp|auto)\b/'],
        ['.380 ACP', '/\b\.?380\s*(?:acp|auto)\b/'],
        ['.22 LR', '/\b\.?22\s*(?:lr|long\s*rifle)\b/'],
        ['10mm Auto', '/\b10\s*mm(?:\s*auto)?\b/'],
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
     * Canonical bullet type => detection pattern, evaluated in order
     * (JHP before HP, the jacket types before the tip types).
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const BULLET_TYPE_PATTERNS = [
        ['JHP', '/\bjhp\b|jacketed\s*hollow\s*points?\b/'],
        ['TMJ', '/\btmj\b|total\s*metal\s*jackets?\b/'],
        ['FMJ', '/\bfmj(?:-?bt)?\b|full\s*metal\s*jackets?\b/'],
        ['OTM', '/\botm\b|open\s*tip\s*match\b/'],
        ['TSX', '/\bt?tsx\b/'],
        ['Frangible', '/\bfrangible\b|\bfrang\b/'],
        ['Subsonic', '/\bsub-?sonic\b/'],
        ['Polymer Tip', '/\bpolymer[-\s]?tip\b|\bpoly[-\s]?tip\b|\bp-?tip\b|\bplastic[-\s]?tip\b/'],
        ['SP', '/\bspbt\b|\bsp\b|\bsoft[-\s]?points?\b/'],
        ['HP', '/\bhp\b|\bhollow[-\s]?points?\b/'],
    ];

    /**
     * Canonical manufacturer => detection pattern. More specific brand
     * names are listed first so "CCI Blazer Brass" resolves to CCI while
     * a bare "Blazer Brass" resolves to Blazer.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const MANUFACTURER_PATTERNS = [
        ['Sellier & Bellot', '/\bsellier\b|\bs\s*&\s*b\b/'],
        ['Sig Sauer', '/\bsig\s*sauer\b|\bsig\b/'],
        ['Federal', '/\bfederal\b|\bfed\b|american\s*eagle/'],
        ['Hornady', '/\bhornady\b|\bhrndy\b/'],
        ['Winchester', '/\bwinchester\b|\bwwb\b|\bwin\b/'],
        ['Remington', '/\bremington\b|\bumc\b|core-?lokt/'],
        ['Speer', '/\bspeer\b|gold\s*dot/'],
        ['CCI', '/\bcci\b|mini-?mag/'],
        ['Blazer', '/\bblazer\b/'],
        ['PMC', '/\bpmc\b/'],
        ['Fiocchi', '/\bfiocchi\b/'],
        ['Magtech', '/\bmagtech\b|\bcbc\b/'],
        ['Aguila', '/\baguila\b/'],
        ['Barnes', '/\bbarnes\b/'],
    ];

    /**
     * Parse a raw description into a structured attribute bag.
     *
     * The distributor SKU, when known, lets round-count parsing tell a
     * box listing from a case listing in "50/1000" slash notation.
     *
     * @return array{
     *     caliber: ?string,
     *     bullet_weight_gr: ?int,
     *     bullet_type: ?string,
     *     rounds_per_box: int,
     *     manufacturer: ?string
     * }
     */
    public function parse(string $description, string $sku = ''): array
    {
        $caliber = $this->parseCaliber($description);

        return [
            'caliber' => $caliber,
            'bullet_weight_gr' => $this->parseBulletWeight($description),
            'bullet_type' => $this->parseBulletType($description),
            'rounds_per_box' => $this->parseRoundsPerBox($description, $caliber, $sku),
            'manufacturer' => $this->parseManufacturer($description),
        ];
    }

    public function parseCaliber(string $text): ?string
    {
        $haystack = $this->normalize($text);

        $isMakarov = (bool) preg_match('/\b9\s*x\s*18\b|makarov|9\s*mm\s*mak\b/', $haystack);

        foreach (self::CALIBER_PATTERNS as [$canonical, $pattern]) {
            if ($canonical === '9mm Luger' && $isMakarov) {
                continue;
            }

            if (preg_match($pattern, $haystack)) {
                return $canonical;
            }
        }

        return null;
    }

    public function parseBulletWeight(string $text): ?int
    {
        if (! preg_match('/(\d{1,4})\s*(?:gr|grn|grain|grains)\b/i', $this->normalize($text), $m)) {
            return null;
        }

        $weight = (int) $m[1];

        return ($weight > 0 && $weight <= 1000) ? $weight : null;
    }

    public function parseBulletType(string $text): ?string
    {
        $haystack = $this->normalize($text);

        foreach (self::BULLET_TYPE_PATTERNS as [$canonical, $pattern]) {
            if (preg_match($pattern, $haystack)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Extract an explicit round/box count, falling back to the
     * conventional default for the caliber family.
     *
     * "Box/case" slash notation ("50/1000") is resolved first, and to the
     * box count for a standard listing, so a plain "N round" match can
     * never latch onto the trailing case number.
     */
    public function parseRoundsPerBox(string $text, ?string $caliber = null, string $sku = ''): int
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

        return $this->defaultRoundsPerBox($caliber);
    }

    public function parseManufacturer(string $text): ?string
    {
        $haystack = $this->normalize($text);

        foreach (self::MANUFACTURER_PATTERNS as [$canonical, $pattern]) {
            if (preg_match($pattern, $haystack)) {
                return $canonical;
            }
        }

        return null;
    }

    private function defaultRoundsPerBox(?string $caliber): int
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
