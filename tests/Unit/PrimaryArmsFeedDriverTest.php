<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\PrimaryArmsFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class PrimaryArmsFeedDriverTest extends TestCase
{
    private string $csvFixture;

    private string $jsonFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csvFixture = dirname(__DIR__).'/Fixtures/primary_arms_sample_feed.csv';
        $this->jsonFixture = dirname(__DIR__).'/Fixtures/primary_arms_sample_feed.json';
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(string $fixture): array
    {
        return iterator_to_array((new PrimaryArmsFeedDriver)->parseFeed($fixture), false);
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

    public function test_csv_endpoint_keeps_only_ammunition(): void
    {
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $this->parse($this->csvFixture));

        $this->assertSame(['PA-FED556', 'PA-BLZ9', 'PA-HOR300', 'PA-CCI22'], $skus);
        $this->assertNotContains('PA-SLX-1-6', $skus, 'a rifle scope must be filtered');
        $this->assertNotContains('PA-MBUS-PRO', $skus, 'a back-up sight must be filtered');
    }

    public function test_csv_column_mapping_and_upc_normalisation(): void
    {
        $dto = $this->bySku($this->parse($this->csvFixture), 'PA-FED556');

        $this->assertSame('604544628567', $dto->raw_upc);
        $this->assertSame('XM193', $dto->raw_mfr_part_number);
        $this->assertSame('Federal 5.56x45 XM193 55gr FMJ 20rd', $dto->raw_description);
        $this->assertSame(7.4, $dto->wholesale_price);
        $this->assertSame(720, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
    }

    public function test_csv_zero_instock_quantity_is_out_of_stock(): void
    {
        $dto = $this->bySku($this->parse($this->csvFixture), 'PA-BLZ9');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
    }

    public function test_json_endpoint_is_parsed_equivalently(): void
    {
        $items = $this->parse($this->jsonFixture);
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $items);

        $this->assertSame(['PA-FED556', 'PA-BLZ9', 'PA-HOR300'], $skus);

        $dto = $this->bySku($items, 'PA-HOR300');
        $this->assertSame('81011', $dto->raw_mfr_part_number);
        $this->assertSame(18.9, $dto->wholesale_price);
        $this->assertSame(140, $dto->quantity_available);
    }
}
