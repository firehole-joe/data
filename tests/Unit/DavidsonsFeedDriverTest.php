<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\DavidsonsFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class DavidsonsFeedDriverTest extends TestCase
{
    private const ITEMSPEC = __DIR__.'/../Fixtures/davidsons_v2_itemspec_sample.csv';

    private const QTY = __DIR__.'/../Fixtures/davidsons_v2_qty_sample.csv';

    private DavidsonsFeedDriver $driver;

    private ?string $mergedPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new DavidsonsFeedDriver;
    }

    protected function tearDown(): void
    {
        if ($this->mergedPath !== null && is_file($this->mergedPath)) {
            @unlink($this->mergedPath);
        }

        parent::tearDown();
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(): array
    {
        $this->mergedPath = $this->driver->mergeItemspecWithQuantities(self::ITEMSPEC, self::QTY);

        return iterator_to_array($this->driver->parseFeed($this->mergedPath), false);
    }

    private function bySku(string $sku): FeedItemDTO
    {
        foreach ($this->parse() as $dto) {
            if ($dto->distributor_sku === $sku) {
                return $dto;
            }
        }

        $this->fail("No parsed row for SKU [{$sku}].");
    }

    /* ------------------------------------------------------------------ */
    /*  V2_Itemspec.csv + V2_Qty.csv merge, by ItemNo */
    /* ------------------------------------------------------------------ */

    public function test_it_merges_quantities_from_v2_qty_by_itemno(): void
    {
        $merged = $this->driver->mergeItemspecWithQuantities(self::ITEMSPEC, self::QTY);
        $this->mergedPath = $merged;

        $rows = array_map('str_getcsv', file($merged, FILE_IGNORE_NEW_LINES));
        $header = array_map('strtolower', $rows[0]);
        $qtyCol = array_search('davidsonsmergedqtyonhand', $header, true);
        $itemCol = array_search('itemno', $header, true);

        $this->assertNotFalse($qtyCol, 'the merge appends a quantity column');

        $bySku = [];
        foreach (array_slice($rows, 1) as $row) {
            $bySku[$row[$itemCol]] = (int) $row[$qtyCol];
        }

        $this->assertSame(600, $bySku['DAV-PMC223']);
        $this->assertSame(0, $bySku['DAV-FED9']);
        $this->assertSame(15, $bySku['DAV-FED9-CASE']);
        // Present in the catalog but absent from V2_Qty.csv: defaults to 0
        // rather than being dropped from the merge.
        $this->assertSame(0, $bySku['DAV-NOQTY']);
    }

    /* ------------------------------------------------------------------ */
    /*  Ammunition filtering */
    /* ------------------------------------------------------------------ */

    public function test_it_keeps_ammunition_and_drops_firearms_and_parts(): void
    {
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $this->parse());

        $this->assertSame(
            ['DAV-PMC223', 'DAV-FED9', 'DAV-FED9-CASE', 'DAV-HOR65', 'DAV-WIN12', 'DAV-EDGE9', 'DAV-NOQTY'],
            $skus,
        );
        $this->assertNotContains('DAV-SPR-HC', $skus, 'a handgun must be filtered out');
        $this->assertNotContains('DAV-AERO-LWR', $skus, 'a stripped lower (Parts) must be filtered out');
    }

    public function test_ammocaliber_alone_is_enough_to_identify_ammunition(): void
    {
        // DAV-HOR65 carries no ProdCat/Category/Subcategory/AmmoCat at
        // all — only AmmoCaliber (and AmmoDesc1) say it is ammunition.
        $dto = $this->bySku('DAV-HOR65');

        $this->assertStringContainsString('Hornady Match 6.5 Creedmoor', $dto->raw_description);
    }

    /* ------------------------------------------------------------------ */
    /*  Column mapping */
    /* ------------------------------------------------------------------ */

    public function test_it_maps_the_iteminventory_columns_and_normalises_the_upc(): void
    {
        $dto = $this->bySku('DAV-PMC223');

        $this->assertSame('741569070430', $dto->raw_upc, 'dirty UPC formatting is stripped');
        $this->assertSame('PMC X-TAC 223 Rem 55gr FMJ 20rd', $dto->raw_description, 'ItemDesc1 + ItemDesc2');
        $this->assertSame(6.85, $dto->wholesale_price);
        $this->assertSame(600, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
        $this->assertSame(20, $dto->raw_round_count);
        $this->assertSame('PMC', $dto->raw_payload['brand']);
    }

    public function test_ammodesc1_is_the_description_fallback_when_itemdesc_is_blank(): void
    {
        $dto = $this->bySku('DAV-HOR65');

        $this->assertSame('Hornady Match 6.5 Creedmoor 140gr ELD Match 20rd', $dto->raw_description);
    }

    public function test_it_flags_zero_quantity_as_out_of_stock(): void
    {
        $dto = $this->bySku('DAV-FED9');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
    }

    public function test_a_sku_missing_from_the_quantity_file_defaults_to_zero(): void
    {
        $dto = $this->bySku('DAV-NOQTY');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
    }

    /* ------------------------------------------------------------------ */
    /*  Pricing: SalePrice vs DealerPrice */
    /* ------------------------------------------------------------------ */

    public function test_sale_price_is_used_when_present_and_positive(): void
    {
        $dto = $this->bySku('DAV-FED9');

        $this->assertSame(7.99, $dto->wholesale_price, 'SalePrice (7.99) undercuts DealerPrice (9.05)');
    }

    public function test_dealer_price_is_used_when_sale_price_is_zero(): void
    {
        $dto = $this->bySku('DAV-HOR65');

        $this->assertSame(25.75, $dto->wholesale_price);
    }

    public function test_dealer_price_is_used_when_sale_price_is_blank(): void
    {
        $dto = $this->bySku('DAV-PMC223');

        $this->assertSame(6.85, $dto->wholesale_price);
    }

    public function test_the_lower_of_sale_and_dealer_price_wins_even_if_sale_is_higher(): void
    {
        // A mispriced SalePrice (20.00) above DealerPrice (9.05) never
        // wins — the lower of the two is always used.
        $dto = $this->bySku('DAV-EDGE9');

        $this->assertSame(9.05, $dto->wholesale_price);
    }

    /* ------------------------------------------------------------------ */
    /*  Packaging / round-count separation */
    /* ------------------------------------------------------------------ */

    public function test_round_per_box_is_used_directly_for_a_retail_box_sku(): void
    {
        $this->assertSame(20, $this->bySku('DAV-PMC223')->raw_round_count);
        $this->assertSame(50, $this->bySku('DAV-FED9')->raw_round_count);
        $this->assertSame(5, $this->bySku('DAV-WIN12')->raw_round_count);
    }

    public function test_box_per_case_multiplies_round_per_box_for_a_case_sku(): void
    {
        // round_per_box=50, box_per_case=20 -> a 1000-round case, kept
        // independent of the 50-round box sharing the same UPC.
        $case = $this->bySku('DAV-FED9-CASE');
        $box = $this->bySku('DAV-FED9');

        $this->assertSame(1000, $case->raw_round_count);
        $this->assertSame(50, $box->raw_round_count);
        $this->assertSame($case->raw_upc, $box->raw_upc, 'the case and box share a UPC');
    }
}
