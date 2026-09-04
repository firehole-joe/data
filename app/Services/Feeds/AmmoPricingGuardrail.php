<?php

namespace App\Services\Feeds;

/**
 * Universal sanity band for wholesale ammunition pricing.
 *
 * Every canonical caliber maps to a tier with a plausible wholesale
 * $/round floor and ceiling. A computed cost-per-round that lands
 * outside that band — or a non-positive round count — is almost always a
 * bad parse (a case count used for a box, a decimal shift, a units
 * mix-up). {@see validate()} reports it so the ingestion pipeline can
 * flag the offering for human review and keep the number out of market
 * averages.
 */
class AmmoPricingGuardrail
{
    public const TIER_RIMFIRE = 'rimfire';

    public const TIER_HANDGUN = 'centerfire_handgun';

    public const TIER_RIFLE = 'centerfire_rifle';

    public const TIER_SHOTSHELL = 'shotshell';

    public const TIER_DEFAULT = 'default';

    /**
     * Absolute wholesale $/round floor for a centerfire pistol / rifle
     * cartridge. A computed cost-per-round below this is not a cheap deal
     * — it is a case count (500 / 1000) being divided into a box price.
     * Such an offering is always flagged and never counts toward the
     * "lowest price" rollups.
     */
    public const CENTERFIRE_HARD_FLOOR = 0.05;

    /**
     * tier => [min $/round, max $/round]
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const TIER_BOUNDS = [
        self::TIER_RIMFIRE => [0.02, 0.60],
        self::TIER_HANDGUN => [0.08, 3.50],
        self::TIER_RIFLE => [0.20, 8.00],
        // Ceiling raised to accommodate premium tungsten / TSS waterfowl
        // and turkey loads (e.g. Federal Premium TSS), which wholesale
        // well above the old $5.00/rd lead-shot band.
        self::TIER_SHOTSHELL => [0.15, 30.00],
        self::TIER_DEFAULT => [0.05, 10.00],
    ];

    /**
     * Canonical caliber (as stored on `master_ammunition.caliber`) => tier.
     *
     * @var array<string, string>
     */
    private const CALIBER_TIERS = [
        '.22 LR' => self::TIER_RIMFIRE,
        '.22 WMR' => self::TIER_RIMFIRE,
        '.17 HMR' => self::TIER_RIMFIRE,

        '9mm Luger' => self::TIER_HANDGUN,
        '.45 ACP' => self::TIER_HANDGUN,
        '.357 Magnum' => self::TIER_HANDGUN,
        '.38 Special' => self::TIER_HANDGUN,
        '.40 S&W' => self::TIER_HANDGUN,
        '10mm Auto' => self::TIER_HANDGUN,
        '.380 ACP' => self::TIER_HANDGUN,

        '5.56x45mm NATO' => self::TIER_RIFLE,
        '.223 Remington' => self::TIER_RIFLE,
        '.300 AAC Blackout' => self::TIER_RIFLE,
        '8.6 Blackout' => self::TIER_RIFLE,
        '6.5 Creedmoor' => self::TIER_RIFLE,
        '.308 Winchester' => self::TIER_RIFLE,

        '12 Gauge' => self::TIER_SHOTSHELL,
        '20 Gauge' => self::TIER_SHOTSHELL,
    ];

    /**
     * The pricing tier a caliber belongs to (default tier when unknown).
     */
    public function tierFor(?string $caliber): string
    {
        if ($caliber === null || trim($caliber) === '') {
            return self::TIER_DEFAULT;
        }

        return self::CALIBER_TIERS[trim($caliber)] ?? self::TIER_DEFAULT;
    }

    /**
     * The [min, max] wholesale $/round band for a caliber's tier.
     *
     * @return array{0: float, 1: float}
     */
    public function boundsFor(?string $caliber): array
    {
        return self::TIER_BOUNDS[$this->tierFor($caliber)];
    }

    /**
     * Validate a wholesale price / round-count pair for a caliber.
     *
     * @return array{is_valid: bool, cost_per_round: float, reason: ?string}
     */
    public function validate(float $wholesalePrice, int $roundCount, ?string $caliber): array
    {
        $tier = $this->tierFor($caliber);
        [$min, $max] = self::TIER_BOUNDS[$tier];

        if ($roundCount <= 0) {
            return $this->result(false, 0.0, 'Could not parse round count');
        }

        $cpr = round($wholesalePrice / $roundCount, 4);

        // No usable wholesale price yet: nothing to judge, and it never
        // reaches the market averages anyway.
        if ($wholesalePrice <= 0.0) {
            return $this->result(true, $cpr, null);
        }

        // Hard safety net, independent of the tier bounds: a centerfire
        // pistol / rifle cartridge under the absolute floor is a
        // case-count-as-box-count parse — always quarantine it.
        if (in_array($tier, [self::TIER_HANDGUN, self::TIER_RIFLE], true)
            && $cpr < self::CENTERFIRE_HARD_FLOOR) {
            return $this->result(false, $cpr, sprintf(
                '$%.4f/rd is below the $%.2f centerfire floor — likely a case count (%d) used as a box count (%s)',
                $cpr, self::CENTERFIRE_HARD_FLOOR, $roundCount, $this->money($wholesalePrice),
            ));
        }

        if ($cpr < $min) {
            return $this->result(false, $cpr, sprintf(
                '$%.4f/rd below the %s floor of $%.2f (%s over %d rds)',
                $cpr, $tier, $min, $this->money($wholesalePrice), $roundCount,
            ));
        }

        if ($cpr > $max) {
            return $this->result(false, $cpr, sprintf(
                '$%.4f/rd exceeds the %s ceiling of $%.2f (%s over %d rds)',
                $cpr, $tier, $max, $this->money($wholesalePrice), $roundCount,
            ));
        }

        return $this->result(true, $cpr, null);
    }

    /**
     * @return array{is_valid: bool, cost_per_round: float, reason: ?string}
     */
    private function result(bool $isValid, float $costPerRound, ?string $reason): array
    {
        return [
            'is_valid' => $isValid,
            'cost_per_round' => $costPerRound,
            'reason' => $reason,
        ];
    }

    private function money(float $value): string
    {
        return '$'.number_format($value, 2);
    }
}
