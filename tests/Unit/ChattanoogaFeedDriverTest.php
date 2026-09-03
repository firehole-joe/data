<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\ChattanoogaFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class ChattanoogaFeedDriverTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = dirname(__DIR__).'/Fixtures/chattanooga_itemInventory_sample.csv';
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(): array
    {
        return iterator_to_array((new ChattanoogaFeedDriver)->parseFeed($this->fixture), false);
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

    public function test_it_keeps_ammunition_rows_and_drops_the_rest(): void
    {
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $this->parse());

        // CSSI-PMC45 and CSSI-CCI22 carry a blank Category and survive
        // only via caliber detection on the description.
        $this->assertSame(
            ['CSSI-AE9', 'CSSI-XM855', 'CSSI-PMC45', 'CSSI-CCI22', 'CSSI-CCI9MM'],
            $skus,
        );
        $this->assertNotContains('CSSI-GLK19', $skus, 'explicit firearm category is filtered');
        $this->assertNotContains('CSSI-VTX-PST', $skus, 'blank category + no caliber is filtered');
        $this->assertNotContains('CSSI-PLANO42', $skus, 'blank category + no caliber is filtered');
    }

    public function test_it_maps_the_iteminventory_columns(): void
    {
        $dto = $this->bySku('CSSI-AE9');

        // "0 76683-90000 1" -> digits only, padded to UPC-A width.
        $this->assertSame('076683900001', $dto->raw_upc);
        $this->assertSame('AE9DP', $dto->raw_mfr_part_number);
        $this->assertSame('FEDERAL AMERICAN EAGLE 9MM LUGER 115GR FMJ 50RD', $dto->raw_description);
        $this->assertSame(8.75, $dto->wholesale_price);
        $this->assertSame(11.99, $dto->map_price);
        $this->assertSame(540, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
        $this->assertSame('Federal', $dto->raw_payload['manufacturer']);
        $this->assertSame('Ammunition', $dto->raw_payload['category']);
    }

    public function test_it_flags_zero_quantity_as_out_of_stock(): void
    {
        $dto = $this->bySku('CSSI-PMC45');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
        $this->assertSame(17.25, $dto->wholesale_price);
    }

    public function test_map_price_is_null_when_the_column_is_blank(): void
    {
        $this->assertNull($this->bySku('CSSI-CCI22')->map_price);
    }
}
