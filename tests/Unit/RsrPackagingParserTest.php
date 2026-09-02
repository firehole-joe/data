<?php

namespace Tests\Unit;

use App\Services\Distributors\RsrPackagingParser;
use PHPUnit\Framework\TestCase;

class RsrPackagingParserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: ?int}>
     */
    public static function roundCountProvider(): array
    {
        return [
            // The reported bug: a plain box listing must resolve to 50.
            'box wins over case' => ['MAGTECH 9MM 115GR FMJ 50/1000', 'MT9A', 50],
            'spaced slash' => ['FEDERAL 5.56 55GR FMJ 20 / 500', 'XM193', 20],
            'slash with trailing unit' => ['PMC 223 55GR FMJ 20/1000RD', 'PMC223', 20],
            'rifle 100/500' => ['SPEER LE 223 55GR FMJ 100/500', 'LE223', 100],

            // Case listings resolve to the case (second) count.
            'case marker -CS in sku' => ['MAGTECH 9MM 115GR FMJ 50/1000', 'MT9A-CS', 1000],
            'case marker CSDP in sku' => ['MAGTECH 9MM 115GR FMJ 50/1000', 'MT9ACSDP', 1000],
            'case marker sku ends CS' => ['MAGTECH 9MM 115GR FMJ 50/1000', 'MT9ACS', 1000],
            'case cue in description' => ['MAGTECH 9MM 115GR FMJ 50/1000 CS', 'MT9A', 1000],
            'case of in description' => ['BLAZER BRASS 9MM 115GR FMJ 50/1000 CASE OF 1000', 'BB9', 1000],

            // Not slash-notation packaging → null (caller keeps its fallback).
            'no slash' => ['CCI MINI-MAG 22LR 40GR 100RD', 'CCI22', null],
            'dual caliber cross-ref' => ['MAGPUL 5.56/223 55GR FMJ 20RD', 'MAG556', null],
            'thread pitch' => ['AR BARREL 16IN 1/2X28 THREADED', 'BBL16', null],
            'case count not round' => ['ODDLOT 9MM 115GR FMJ 50/333', 'ODD9', null],
            'plain description' => ['FEDERAL HST 9MM 124GR JHP 50-ROUND BOX', 'P9HST3', null],
        ];
    }

    /**
     * @dataProvider roundCountProvider
     */
    public function test_it_extracts_the_round_count_from_slash_notation(string $description, string $sku, ?int $expected): void
    {
        $this->assertSame($expected, RsrPackagingParser::extractRoundCount($description, $sku));
    }

    public function test_the_reported_regression_resolves_to_a_sane_cost_per_round(): void
    {
        $count = RsrPackagingParser::extractRoundCount('MAGTECH 9MM 115GR FMJ 50/1000', 'MT9A');

        $this->assertSame(50, $count);
        $this->assertEqualsWithDelta(0.258, round(12.88 / $count, 3), 0.001);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function caseListingProvider(): array
    {
        return [
            'sku dash cs' => ['MAGTECH 9MM 50/1000', 'MT9A-CS', true],
            'sku csdp' => ['MAGTECH 9MM 50/1000', 'RSRMT9ACSDP', true],
            'sku ends cs' => ['MAGTECH 9MM 50/1000', 'MT9ACS', true],
            'desc cs token' => ['MAGTECH 9MM 50/1000 CS', 'MT9A', true],
            'desc case of' => ['MAGTECH 9MM CASE OF 1000', 'MT9A', true],
            'plain box' => ['MAGTECH 9MM 115GR FMJ 50/1000', 'MT9A', false],
            'brass case is not a case listing' => ['MAGTECH 9MM 115GR FMJ BRASS CASE 50RD', 'MT9A', false],
        ];
    }

    /**
     * @dataProvider caseListingProvider
     */
    public function test_it_identifies_case_listings(string $description, string $sku, bool $expected): void
    {
        $this->assertSame($expected, RsrPackagingParser::isCaseListing($description, $sku));
    }
}
