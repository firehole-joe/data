<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\ZandersFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class ZandersFeedDriverTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = dirname(__DIR__).'/Fixtures/zanders_sample_feed.csv';
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(): array
    {
        return iterator_to_array((new ZandersFeedDriver)->parseFeed($this->fixture), false);
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

    public function test_it_keeps_only_ammo_categories(): void
    {
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $this->parse());

        $this->assertSame(['Z-CCI9MM', 'Z-FED556', 'Z-PMC45', 'Z-HORN65'], $skus);
        $this->assertNotContains('Z-REM870', $skus, 'shotgun category must be filtered');
        $this->assertNotContains('Z-LEUP', $skus, 'optics category must be filtered');
    }

    public function test_it_maps_csv_columns_and_normalises_a_dirty_upc(): void
    {
        $dto = $this->bySku('Z-CCI9MM');

        $this->assertSame('076683035288', $dto->raw_upc);
        $this->assertSame('CCI Blazer Brass 9mm 115gr FMJ 50rd', $dto->raw_description);
        $this->assertSame(9.1, $dto->wholesale_price);
        $this->assertSame(300, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
        $this->assertNull($dto->map_price);
    }

    public function test_it_flags_zero_quantity_as_out_of_stock(): void
    {
        $dto = $this->bySku('Z-PMC45');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
    }
}
