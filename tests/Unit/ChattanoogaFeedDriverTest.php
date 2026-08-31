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

        $this->fixture = dirname(__DIR__).'/Fixtures/chattanooga_sample_feed.txt';
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

    public function test_it_keeps_only_ammunition_categories(): void
    {
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $this->parse());

        $this->assertSame(['CH-AE9', 'CH-WIN223', 'CH-PMC45', 'CH-CCI22'], $skus);
        $this->assertNotContains('CH-GLK19', $skus, 'handgun category must be filtered');
        $this->assertNotContains('CH-VTX-PST', $skus, 'optics category must be filtered');
    }

    public function test_it_maps_pipe_delimited_columns(): void
    {
        $dto = $this->bySku('CH-AE9');

        $this->assertSame('604544617658', $dto->raw_upc);
        $this->assertSame('FEDERAL AMERICAN EAGLE 9MM 115GR FMJ 50RD', $dto->raw_description);
        $this->assertSame(8.75, $dto->wholesale_price);
        $this->assertSame(11.99, $dto->map_price);
        $this->assertSame(540, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
        $this->assertSame('9mm Luger', $dto->raw_payload['caliber']);
    }

    public function test_it_flags_zero_quantity_as_out_of_stock(): void
    {
        $dto = $this->bySku('CH-PMC45');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
        $this->assertSame(17.25, $dto->wholesale_price);
    }

    public function test_map_price_is_null_when_the_column_is_blank(): void
    {
        $this->assertNull($this->bySku('CH-CCI22')->map_price);
    }
}
