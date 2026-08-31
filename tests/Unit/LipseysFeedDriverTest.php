<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\LipseysFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class LipseysFeedDriverTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = dirname(__DIR__).'/Fixtures/lipseys_sample_feed.csv';
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(): array
    {
        return iterator_to_array((new LipseysFeedDriver)->parseFeed($this->fixture), false);
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

    public function test_it_separates_ammunition_from_firearms(): void
    {
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $this->parse());

        $this->assertSame(['LIP-FGM308', 'LIP-BLK300', 'LIP-CCI22', 'LIP-AE9'], $skus);
        $this->assertNotContains('LIP-GLK19X', $skus, 'a pistol must be filtered out');
        $this->assertNotContains('LIP-SW686', $skus, 'a revolver must be filtered out');
    }

    public function test_it_maps_the_full_lipseys_column_set(): void
    {
        $dto = $this->bySku('LIP-FGM308');

        $this->assertSame('604544622492', $dto->raw_upc);
        $this->assertSame('GM308M', $dto->raw_mfr_part_number);
        $this->assertSame(26.5, $dto->wholesale_price);
        $this->assertSame(32.99, $dto->map_price);
        $this->assertSame(150, $dto->quantity_available);
        $this->assertStringContainsString('Gold Medal', $dto->raw_description);
        $this->assertSame('Federal', $dto->raw_payload['manufacturer']);
        $this->assertSame('308 Winchester', $dto->raw_payload['caliber']);
    }

    public function test_it_normalises_a_dirty_upc_and_reads_map(): void
    {
        $dto = $this->bySku('LIP-AE9');

        $this->assertSame('604544617658', $dto->raw_upc);
        $this->assertSame(12.49, $dto->map_price);
    }

    public function test_zero_quantity_is_out_of_stock_and_blank_map_is_null(): void
    {
        $dto = $this->bySku('LIP-BLK300');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
        $this->assertNull($dto->map_price);
    }
}
