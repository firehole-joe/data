<?php

namespace Tests\Unit;

use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;

class FeedItemDTOTest extends TestCase
{
    public function test_from_array_populates_and_normalises_all_properties(): void
    {
        $dto = FeedItemDTO::fromArray([
            'distributor_sku' => '  ABC123  ',
            'raw_upc' => '000123456789',
            'raw_mfr_part_number' => 'MP-1',
            'raw_description' => '  Some ammo  ',
            'wholesale_price' => '12.50',
            'map_price' => '15.00',
            'msrp_price' => '19.99',
            'quantity_available' => '42',
            'raw_payload' => ['source' => 'row-1'],
        ]);

        $this->assertSame('ABC123', $dto->distributor_sku);
        $this->assertSame('000123456789', $dto->raw_upc);
        $this->assertSame('MP-1', $dto->raw_mfr_part_number);
        $this->assertSame('Some ammo', $dto->raw_description);
        $this->assertSame(12.5, $dto->wholesale_price);
        $this->assertSame(15.0, $dto->map_price);
        $this->assertSame(19.99, $dto->msrp_price);
        $this->assertSame(42, $dto->quantity_available);
        $this->assertTrue($dto->is_in_stock);
        $this->assertSame(['source' => 'row-1'], $dto->raw_payload);
    }

    public function test_is_in_stock_is_derived_from_quantity_when_not_supplied(): void
    {
        $this->assertFalse(FeedItemDTO::fromArray(['distributor_sku' => 'A', 'quantity_available' => 0])->is_in_stock);
        $this->assertTrue(FeedItemDTO::fromArray(['distributor_sku' => 'A', 'quantity_available' => 3])->is_in_stock);
    }

    public function test_explicit_is_in_stock_flag_is_respected(): void
    {
        $dto = FeedItemDTO::fromArray([
            'distributor_sku' => 'A',
            'quantity_available' => 0,
            'is_in_stock' => true,
        ]);

        $this->assertTrue($dto->is_in_stock);
    }

    public function test_blank_and_non_numeric_nullable_values_become_null(): void
    {
        $dto = FeedItemDTO::fromArray([
            'distributor_sku' => 'A',
            'raw_upc' => '   ',
            'raw_mfr_part_number' => '',
            'map_price' => '',
            'msrp_price' => 'N/A',
            'quantity_available' => 1,
        ]);

        $this->assertNull($dto->raw_upc);
        $this->assertNull($dto->raw_mfr_part_number);
        $this->assertNull($dto->map_price);
        $this->assertNull($dto->msrp_price);
    }

    public function test_negative_quantity_is_clamped_to_zero(): void
    {
        $dto = FeedItemDTO::fromArray(['distributor_sku' => 'A', 'quantity_available' => -5]);

        $this->assertSame(0, $dto->quantity_available);
        $this->assertFalse($dto->is_in_stock);
    }

    public function test_to_array_round_trips_a_fully_specified_payload(): void
    {
        $data = [
            'distributor_sku' => 'SKU1',
            'raw_upc' => null,
            'raw_mfr_part_number' => null,
            'raw_description' => 'desc',
            'wholesale_price' => 1.23,
            'map_price' => null,
            'msrp_price' => null,
            'quantity_available' => 0,
            'is_in_stock' => false,
            'raw_payload' => [],
            'raw_round_count' => null,
        ];

        $this->assertSame($data, FeedItemDTO::fromArray($data)->toArray());
    }

    public function test_raw_round_count_is_captured_when_positive_and_null_otherwise(): void
    {
        $this->assertSame(500, FeedItemDTO::fromArray([
            'distributor_sku' => 'A', 'quantity_available' => 1, 'raw_round_count' => '500',
        ])->raw_round_count);

        $this->assertNull(FeedItemDTO::fromArray([
            'distributor_sku' => 'A', 'quantity_available' => 1, 'raw_round_count' => 0,
        ])->raw_round_count);

        $this->assertNull(FeedItemDTO::fromArray([
            'distributor_sku' => 'A', 'quantity_available' => 1,
        ])->raw_round_count);
    }
}
