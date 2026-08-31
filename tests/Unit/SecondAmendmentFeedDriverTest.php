<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\SecondAmendmentFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class SecondAmendmentFeedDriverTest extends TestCase
{
    private string $jsonFixture;

    private string $csvFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jsonFixture = dirname(__DIR__).'/Fixtures/second_amendment_sample_feed.json';
        $this->csvFixture = dirname(__DIR__).'/Fixtures/second_amendment_sample_feed.csv';
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(string $fixture): array
    {
        return iterator_to_array((new SecondAmendmentFeedDriver)->parseFeed($fixture), false);
    }

    private function bySku(array $items, string $sku): FeedItemDTO
    {
        foreach ($items as $dto) {
            if ($dto->distributor_sku === $sku) {
                return $dto;
            }
        }

        $this->fail("No parsed row for SKU [{$sku}].");
    }

    public function test_it_parses_the_json_product_array_and_filters_non_ammo(): void
    {
        $items = $this->parse($this->jsonFixture);
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $items);

        $this->assertSame(['2A-SPR9', '2A-FED556', '2A-HOR308'], $skus);
        $this->assertNotContains('2A-VTX-SE', $skus, 'optics category must be filtered');
    }

    public function test_it_maps_json_fields_with_safe_numeric_parsing(): void
    {
        $items = $this->parse($this->jsonFixture);
        $dto = $this->bySku($items, '2A-SPR9');

        $this->assertSame('604544617184', $dto->raw_upc);
        $this->assertSame('GD9124', $dto->raw_mfr_part_number);
        $this->assertSame('Speer Gold Dot 9mm Luger 124gr JHP 20rd', $dto->raw_description);
        $this->assertSame(14.2, $dto->wholesale_price);
        $this->assertSame(21.99, $dto->map_price);
        $this->assertSame(180, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
    }

    public function test_json_zero_quantity_and_dirty_upc(): void
    {
        $dto = $this->bySku($this->parse($this->jsonFixture), '2A-FED556');

        $this->assertSame('604544628567', $dto->raw_upc);
        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
        $this->assertSame(8.05, $dto->wholesale_price);
    }

    public function test_the_csv_fallback_yields_the_same_rows(): void
    {
        $skus = array_map(
            fn (FeedItemDTO $dto) => $dto->distributor_sku,
            $this->parse($this->csvFixture),
        );

        $this->assertSame(['2A-SPR9', '2A-FED556', '2A-HOR308'], $skus);
    }
}
