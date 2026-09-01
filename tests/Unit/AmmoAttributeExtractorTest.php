<?php

namespace Tests\Unit;

use App\Services\Ammunition\AmmoAttributeExtractor;
use PHPUnit\Framework\TestCase;

class AmmoAttributeExtractorTest extends TestCase
{
    private AmmoAttributeExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new AmmoAttributeExtractor;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function caliberProvider(): array
    {
        return [
            '9mm bare' => ['9mm 115gr FMJ', '9mm Luger'],
            '9mm Luger' => ['Winchester 9mm Luger 115gr', '9mm Luger'],
            '9x19' => ['CCI 9X19 124GR', '9mm Luger'],
            '.45 ACP' => ['.45 ACP 230gr FMJ', '.45 ACP'],
            '.45 Auto' => ['Federal 45 Auto 230gr', '.45 ACP'],
            '.357 Magnum' => ['Magtech .357 Magnum 158gr SP', '.357 Magnum'],
            '.357 Mag short' => ['357 MAG 125GR JHP', '.357 Magnum'],
            '.38 Special' => ['.38 Special 158gr LRN', '.38 Special'],
            '.38 Spl short' => ['38 SPL +P 130GR', '.38 Special'],
            '.380 ACP' => ['Fiocchi .380 ACP 95gr FMJ', '.380 ACP'],
            '.40 S&W' => ['Speer .40 S&W 180gr', '.40 S&W'],
            '40 SW no amp' => ['FED 40SW 165GR FMJ', '.40 S&W'],
            '10mm Auto' => ['Sig Sauer 10mm Auto 180gr', '10mm Auto'],
            '5.56 bare' => ['XM193 5.56 55GR', '5.56x45mm NATO'],
            '5.56x45' => ['5.56x45mm NATO 62gr', '5.56x45mm NATO'],
            '.223 Rem' => ['FED AE 223REM 55GR FMJ 20RD', '.223 Remington'],
            '.300 Blackout' => ['Barnes .300 Blackout 110gr', '.300 AAC Blackout'],
            '300 BLK subsonic' => ['300 BLK 220GR SUBSONIC', '.300 AAC Blackout'],
            '8.6 Blackout' => ['8.6 Blackout 210gr', '8.6 Blackout'],
            '6.5 Creedmoor' => ['Hornady 6.5 Creedmoor 140gr ELD-M', '6.5 Creedmoor'],
            '.308 Win' => ['.308 Win 150gr SP', '.308 Winchester'],
            '7.62x51' => ['7.62x51 147gr FMJ', '.308 Winchester'],
            '.22 LR' => ['CCI .22 LR 40gr', '.22 LR'],
            '12 Gauge' => ['Federal 12 GA 00 Buck', '12 Gauge'],
        ];
    }

    /**
     * @dataProvider caliberProvider
     */
    public function test_it_normalises_calibers(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->extractor->extractCaliber($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownCaliberProvider(): array
    {
        return [
            '.357 Sig is not .357 Mag' => ['.357 SIG 125GR FMJ'],
            '9mm Makarov' => ['9mm Makarov 95gr FMJ'],
            '7.62x39' => ['7.62x39 123gr FMJ'],
            'no caliber' => ['Cleaning kit and range bag'],
        ];
    }

    /**
     * @dataProvider unknownCaliberProvider
     */
    public function test_it_returns_null_for_unrecognised_or_out_of_scope_calibers(string $raw): void
    {
        $this->assertNull($this->extractor->extractCaliber($raw));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function projectileProvider(): array
    {
        return [
            'FMJ' => ['55gr FMJ', 'FMJ'],
            'full metal jacket' => ['Full Metal Jacket 115gr', 'FMJ'],
            'TMJ' => ['165gr TMJ', 'TMJ'],
            'JHP over HP' => ['124gr JHP', 'JHP'],
            'HP' => ['110gr HP', 'HP'],
            'hollow point' => ['Hollow Point 147gr', 'HP'],
            'OTM' => ['175gr OTM', 'OTM'],
            'open tip match' => ['168gr Open Tip Match', 'OTM'],
            'soft point' => ['150gr Soft Point', 'SP'],
            'SP token' => ['150gr SP', 'SP'],
            'polymer tip' => ['140gr Polymer Tip', 'Polymer Tip'],
            'v-max' => ['Hornady 40gr V-MAX', 'Polymer Tip'],
            'frangible' => ['100gr Frangible', 'Frangible'],
            'subsonic' => ['220gr Subsonic', 'Subsonic'],
            'monolithic' => ['Barnes 110gr TSX Monolithic', 'Monolithic Solid Copper'],
            'solid copper' => ['Lehigh Defense 90gr Solid Copper', 'Monolithic Solid Copper'],
            'none' => ['.223 Remington 55gr', null],
        ];
    }

    /**
     * @dataProvider projectileProvider
     */
    public function test_it_normalises_projectile_type(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->extractor->extractProjectileType($raw));
    }

    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function grainProvider(): array
    {
        return [
            'gr suffix' => ['55gr FMJ', 55],
            'grain word' => ['124 grain JHP', 124],
            'spaced GR upper' => ['230 GR TMJ', 230],
            'inside description' => ['FED AE 223REM 55GR FMJ 20RD', 55],
            '158gr revolver' => ['.357 Magnum 158gr SP', 158],
            'none' => ['9mm Luger FMJ 50rd', null],
            'implausible' => ['9999gr', null],
        ];
    }

    /**
     * @dataProvider grainProvider
     */
    public function test_it_extracts_grain_weight(string $raw, ?int $expected): void
    {
        $this->assertSame($expected, $this->extractor->extractGrainWeight($raw));
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: int}>
     */
    public static function roundCountProvider(): array
    {
        return [
            'rd suffix' => ['20RD', null, 20],
            'rounds word' => ['50 rounds', null, 50],
            'case of N' => ['case of 1000', null, 1000],
            'count token' => ['1000 CT', null, 1000],
            'rifle default' => ['Federal .223 Remington 55gr FMJ', '.223 Remington', 20],
            'handgun default' => ['Speer 9mm 124gr', '9mm Luger', 50],
            'shotgun default' => ['Federal 12 Gauge 00 Buck', '12 Gauge', 25],
            'unknown default' => ['mystery ammo', null, 50],
        ];
    }

    /**
     * @dataProvider roundCountProvider
     */
    public function test_it_extracts_or_defaults_round_count(string $raw, ?string $caliber, int $expected): void
    {
        $this->assertSame($expected, $this->extractor->extractRoundCount($raw, $caliber));
    }

    /**
     * @return array<string, array{0: ?float, 1: ?int, 2: ?float}>
     */
    public static function cprProvider(): array
    {
        return [
            'clean division' => [12.50, 50, 0.25],
            'rifle box' => [17.00, 20, 0.85],
            'bulk case' => [289.99, 1000, 0.29],
            'zero price' => [0.0, 50, null],
            'zero count' => [12.50, 0, null],
            'null price' => [null, 50, null],
        ];
    }

    /**
     * @dataProvider cprProvider
     */
    public function test_it_computes_cost_per_round(?float $price, ?int $count, ?float $expected): void
    {
        $this->assertSame($expected, $this->extractor->costPerRound($price, $count));
    }

    public function test_extract_returns_a_complete_structured_bag_with_cpr(): void
    {
        $bag = $this->extractor->extract('Federal American Eagle .223 Rem 55gr FMJ 20-round box', 17.00);

        $this->assertSame([
            'caliber' => '.223 Remington',
            'projectile_type' => 'FMJ',
            'grain_weight' => 55,
            'round_count' => 20,
            'round_count_explicit' => true,
            'cost_per_round' => 0.85,
        ], $bag);
    }

    public function test_extract_degrades_gracefully_on_junk(): void
    {
        $bag = $this->extractor->extract('Assorted range brass, mixed headstamp');

        $this->assertNull($bag['caliber']);
        $this->assertNull($bag['projectile_type']);
        $this->assertNull($bag['grain_weight']);
        $this->assertFalse($bag['round_count_explicit']);
        $this->assertSame(50, $bag['round_count']);
        $this->assertNull($bag['cost_per_round']);
    }

    public function test_extract_uses_family_default_round_count_for_cpr_when_not_stated(): void
    {
        $bag = $this->extractor->extract('Speer Gold Dot 9mm Luger 124gr JHP', 22.50);

        $this->assertSame(50, $bag['round_count']);
        $this->assertFalse($bag['round_count_explicit']);
        $this->assertSame(0.45, $bag['cost_per_round']);
    }
}
