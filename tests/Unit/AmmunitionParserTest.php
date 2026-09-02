<?php

namespace Tests\Unit;

use App\Services\Matching\AmmunitionParser;
use PHPUnit\Framework\TestCase;

class AmmunitionParserTest extends TestCase
{
    private AmmunitionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new AmmunitionParser;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function caliberProvider(): array
    {
        return [
            '9mm' => ['9mm', '9mm Luger'],
            '9mm Luger' => ['Winchester 9mm Luger 115gr', '9mm Luger'],
            '9x19' => ['CCI 9X19 124GR', '9mm Luger'],
            '9mm Parabellum' => ['9mm Parabellum FMJ', '9mm Luger'],
            '5.56 bare' => ['XM193 5.56 55GR', '5.56x45mm NATO'],
            '5.56 NATO' => ['5.56 NATO 62gr', '5.56x45mm NATO'],
            '5.56x45' => ['5.56x45 55gr FMJ', '5.56x45mm NATO'],
            '5.56x45mm' => ['5.56x45mm NATO 55gr', '5.56x45mm NATO'],
            '.223' => ['.223 55gr', '.223 Remington'],
            '.223 Rem' => ['FED .223 REM 55GR', '.223 Remington'],
            '.223 Remington' => ['Hornady .223 Remington 75gr', '.223 Remington'],
            '223 no dot no space' => ['FED AE 223REM 55GR FMJ 20RD', '.223 Remington'],
            '.300 Blackout' => ['Barnes .300 Blackout 110gr', '.300 AAC Blackout'],
            '300 BLK' => ['300 BLK 220GR SUBSONIC', '.300 AAC Blackout'],
            '300 AAC' => ['300 AAC 125gr', '.300 AAC Blackout'],
            '300 Blackout no dot' => ['300 Blackout 150gr', '.300 AAC Blackout'],
            '8.6 Blackout' => ['8.6 Blackout 210gr', '8.6 Blackout'],
            '8.6 BLK' => ['8.6 BLK subsonic', '8.6 Blackout'],
            '6.5 Creedmoor' => ['Hornady 6.5 Creedmoor 140gr ELD', '6.5 Creedmoor'],
            '6.5 CM' => ['6.5 CM 143GR', '6.5 Creedmoor'],
            '6.5 CRDMR' => ['6.5 CRDMR 120gr', '6.5 Creedmoor'],
            '.308 Win' => ['.308 Win 150gr', '.308 Winchester'],
            '.308 Winchester' => ['Federal .308 Winchester 168gr', '.308 Winchester'],
            '7.62x51' => ['7.62x51 147gr FMJ', '.308 Winchester'],
            '7.62 NATO' => ['7.62 NATO M80 147gr', '.308 Winchester'],
            '.45 ACP' => ['.45 ACP 230gr FMJ', '.45 ACP'],
            '.45 Auto' => ['CCI .45 Auto 230gr', '.45 ACP'],
            '.22 LR' => ['CCI .22 LR 40gr', '.22 LR'],
            '.22 Long Rifle' => ['Aguila .22 Long Rifle 40gr', '.22 LR'],
            '.380 ACP' => ['.380 ACP 95gr FMJ', '.380 ACP'],
            '.380 Auto' => ['Fiocchi .380 Auto 90gr', '.380 ACP'],
            '10mm' => ['10mm 180gr', '10mm Auto'],
            '10mm Auto' => ['Sig Sauer 10mm Auto 180gr', '10mm Auto'],
            '12 GA' => ['12 GA 00 Buck', '12 Gauge'],
            '12 Gauge' => ['Federal 12 Gauge 2.75in', '12 Gauge'],
            '12GA no space' => ['12GA Slug', '12 Gauge'],
        ];
    }

    /**
     * @dataProvider caliberProvider
     */
    public function test_it_normalises_calibers(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->parser->parseCaliber($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownCaliberProvider(): array
    {
        return [
            '7.62x39' => ['7.62x39 123gr FMJ'],
            '.45 Colt' => ['.45 Colt 250gr'],
            '.22 WMR' => ['.22 WMR 30gr'],
            '20 Gauge' => ['20 Gauge #7.5'],
            '9mm Makarov' => ['9mm Makarov 95gr FMJ'],
            '9x18' => ['9x18 Makarov'],
            'no caliber' => ['Cleaning kit and range bag'],
        ];
    }

    /**
     * @dataProvider unknownCaliberProvider
     */
    public function test_it_returns_null_for_unrecognised_or_out_of_scope_calibers(string $raw): void
    {
        $this->assertNull($this->parser->parseCaliber($raw));
    }

    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function weightProvider(): array
    {
        return [
            'gr suffix' => ['55gr FMJ', 55],
            'grain word' => ['124 grain JHP', 124],
            'grains word' => ['147grains FMJ', 147],
            'spaced GR upper' => ['230 GR TMJ', 230],
            'inside description' => ['FED AE 223REM 55GR FMJ 20RD', 55],
            'none' => ['9mm Luger FMJ 50rd', null],
            'implausible' => ['9999gr', null],
        ];
    }

    /**
     * @dataProvider weightProvider
     */
    public function test_it_extracts_bullet_weight(string $raw, ?int $expected): void
    {
        $this->assertSame($expected, $this->parser->parseBulletWeight($raw));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function bulletTypeProvider(): array
    {
        return [
            'FMJ' => ['55gr FMJ', 'FMJ'],
            'full metal jacket' => ['Full Metal Jacket 115gr', 'FMJ'],
            'TMJ' => ['165gr TMJ', 'TMJ'],
            'JHP over HP' => ['124gr JHP', 'JHP'],
            'HP' => ['110gr HP', 'HP'],
            'hollow point' => ['Hollow Point 147gr', 'HP'],
            'OTM' => ['175gr OTM', 'OTM'],
            'TSX' => ['Barnes 168gr TSX', 'TSX'],
            'subsonic' => ['220gr Subsonic', 'Subsonic'],
            'soft point' => ['150gr Soft Point', 'SP'],
            'SP token' => ['150gr SP', 'SP'],
            'polymer tip' => ['140gr Polymer Tip', 'Polymer Tip'],
            'frangible' => ['100gr Frangible', 'Frangible'],
            'none' => ['.223 Remington 55gr', null],
        ];
    }

    /**
     * @dataProvider bulletTypeProvider
     */
    public function test_it_normalises_bullet_type(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser->parseBulletType($raw));
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: int}>
     */
    public static function roundsProvider(): array
    {
        return [
            'rd suffix' => ['20RD', null, 20],
            'rounds word' => ['50 rounds', null, 50],
            'box of N' => ['case of 500', null, 500],
            'count' => ['1000 CT', null, 1000],
            'rifle default' => ['Federal .223 Remington 55gr FMJ', '.223 Remington', 20],
            'handgun default' => ['Speer 9mm 124gr', '9mm Luger', 50],
            'shotgun default' => ['Federal 12 Gauge 00 Buck', '12 Gauge', 25],
            'unknown default' => ['mystery ammo', null, 50],
            // Box/case slash notation resolves to the box count, never the
            // case count, even when a trailing "RD" follows the case number.
            'slash notation box' => ['MAGTECH 9MM 115GR FMJ 50/1000', '9mm Luger', 50],
            'slash notation box with unit' => ['PMC 223 55GR FMJ 20/1000RD', '.223 Remington', 20],
        ];
    }

    /**
     * @dataProvider roundsProvider
     */
    public function test_it_extracts_or_defaults_rounds_per_box(string $raw, ?string $caliber, int $expected): void
    {
        $this->assertSame($expected, $this->parser->parseRoundsPerBox($raw, $caliber));
    }

    public function test_slash_notation_honours_the_sku_case_marker(): void
    {
        $description = 'MAGTECH 9MM 115GR FMJ 50/1000';

        $this->assertSame(50, $this->parser->parseRoundsPerBox($description, '9mm Luger', 'MT9A'));
        $this->assertSame(1000, $this->parser->parseRoundsPerBox($description, '9mm Luger', 'MT9A-CS'));

        $parsed = $this->parser->parse($description, 'MT9A');
        $this->assertSame(50, $parsed['rounds_per_box']);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function manufacturerProvider(): array
    {
        return [
            'Federal / American Eagle' => ['FED AE 223REM 55GR', 'Federal'],
            'Hornady' => ['HORNADY MATCH 6.5CM 140GR', 'Hornady'],
            'Winchester USA' => ['WIN USA 9MM 115GR FMJ', 'Winchester'],
            'PMC' => ['PMC BRONZE 9MM 115GR', 'PMC'],
            'Speer Gold Dot' => ['SPEER GOLD DOT 9MM 124GR', 'Speer'],
            'CCI' => ['CCI MINI-MAG 22LR 40GR', 'CCI'],
            'CCI before Blazer' => ['CCI BLAZER BRASS 9MM 115GR', 'CCI'],
            'Blazer alone' => ['BLAZER BRASS 380 AUTO 95GR', 'Blazer'],
            'Remington UMC' => ['REM UMC 45 AUTO 230GR', 'Remington'],
            'Fiocchi' => ['FIOCCHI 9MM 115GR FMJ', 'Fiocchi'],
            'Magtech' => ['MAGTECH 9MM 124GR FMJ', 'Magtech'],
            'Aguila' => ['AGUILA 22LR 40GR', 'Aguila'],
            'Sellier & Bellot' => ['S&B 9MM 115GR FMJ', 'Sellier & Bellot'],
            'Barnes' => ['BARNES VOR-TX 300BLK 110GR', 'Barnes'],
            'Sig Sauer' => ['SIG SAUER ELITE 9MM 124GR', 'Sig Sauer'],
            'unknown' => ['Generic ammo can, 30 cal', null],
        ];
    }

    /**
     * @dataProvider manufacturerProvider
     */
    public function test_it_identifies_manufacturer(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->parser->parseManufacturer($raw));
    }

    public function test_parse_returns_a_complete_structured_bag(): void
    {
        $parsed = $this->parser->parse('Federal American Eagle .223 Rem 55gr FMJ 20-round box');

        $this->assertSame([
            'caliber' => '.223 Remington',
            'bullet_weight_gr' => 55,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 20,
            'manufacturer' => 'Federal',
        ], $parsed);
    }

    public function test_parse_degrades_gracefully_on_junk(): void
    {
        $parsed = $this->parser->parse('Assorted range brass, mixed headstamp');

        $this->assertNull($parsed['caliber']);
        $this->assertNull($parsed['bullet_weight_gr']);
        $this->assertNull($parsed['bullet_type']);
        $this->assertNull($parsed['manufacturer']);
        $this->assertSame(50, $parsed['rounds_per_box']);
    }
}
