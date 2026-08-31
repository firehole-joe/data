<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\DavidsonsFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class DavidsonsFeedDriverTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = dirname(__DIR__).'/Fixtures/davidsons_sample_feed.csv';
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(): array
    {
        return iterator_to_array((new DavidsonsFeedDriver)->parseFeed($this->fixture), false);
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

    public function test_it_detects_ammunition_from_the_description(): void
    {
        $skus = array_map(fn (FeedItemDTO $dto) => $dto->distributor_sku, $this->parse());

        $this->assertSame(['DAV-PMC223', 'DAV-FED9', 'DAV-HOR65', 'DAV-WIN12'], $skus);
        $this->assertNotContains('DAV-SPR-HC', $skus, 'a pistol must be filtered out');
        $this->assertNotContains('DAV-AERO-LWR', $skus, 'a stripped lower must be filtered out');
    }

    public function test_it_maps_columns_and_normalises_the_upc(): void
    {
        $dto = $this->bySku('DAV-PMC223');

        $this->assertSame('741569070430', $dto->raw_upc);
        $this->assertSame('PMC X-TAC 223 Rem 55gr FMJ 20rd', $dto->raw_description);
        $this->assertSame(6.85, $dto->wholesale_price);
        $this->assertSame(600, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
        $this->assertSame('PMC', $dto->raw_payload['manufacturer']);
    }

    public function test_it_keeps_shotshell_loads(): void
    {
        $dto = $this->bySku('DAV-WIN12');

        $this->assertSame(240, $dto->quantity_available);
        $this->assertStringContainsString('00 Buck', $dto->raw_description);
    }

    public function test_it_flags_zero_quantity_as_out_of_stock(): void
    {
        $dto = $this->bySku('DAV-FED9');

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
    }
}
