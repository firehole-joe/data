<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\CrowFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class CrowFeedDriverTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = dirname(__DIR__).'/Fixtures/crow_sample_feed.csv';
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(): array
    {
        return iterator_to_array((new CrowFeedDriver)->parseFeed($this->fixture), false);
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

    public function test_it_filters_on_the_department_column(): void
    {
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $this->parse());

        $this->assertSame(['CRW-BLZ9', 'CRW-FED22', 'CRW-WIN556', 'CRW-MAG10'], $skus);
        $this->assertNotContains('CRW-BUR-FF3', $skus);
        $this->assertNotContains('CRW-BH-SERPA', $skus);
    }

    public function test_it_maps_the_flat_file_columns(): void
    {
        $dto = $this->bySku('CRW-BLZ9');

        $this->assertSame('076683902900', $dto->raw_upc);
        $this->assertSame('Blazer Brass 9mm 124gr FMJ 50rd', $dto->raw_description);
        $this->assertSame(9.35, $dto->wholesale_price);
        $this->assertSame(275, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
    }

    public function test_it_flags_zero_quantity_as_out_of_stock(): void
    {
        $dto = $this->bySku('CRW-FED22');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
    }
}
