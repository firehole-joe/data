<?php

namespace App\Services\Feeds\DTOs;

/**
 * Normalised representation of a single row from a distributor feed.
 *
 * Drivers are responsible for translating their vendor-specific columns
 * into this shape; everything downstream (the ingestion service, tests)
 * only ever deals with a FeedItemDTO.
 */
class FeedItemDTO
{
    /**
     * @param  array<string, mixed>  $raw_payload  The untouched source row, kept for debugging/auditing.
     */
    public function __construct(
        public string $distributor_sku,
        public ?string $raw_upc,
        public ?string $raw_mfr_part_number,
        public string $raw_description,
        public float $wholesale_price,
        public ?float $map_price,
        public ?float $msrp_price,
        public int $quantity_available,
        public bool $is_in_stock,
        public array $raw_payload = [],
    ) {}

    /**
     * Build a DTO from a loosely-typed associative array.
     *
     * `is_in_stock` is derived from `quantity_available` when it is not
     * explicitly provided.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $quantity = (int) ($data['quantity_available'] ?? 0);

        return new self(
            distributor_sku: trim((string) ($data['distributor_sku'] ?? '')),
            raw_upc: self::nullableString($data['raw_upc'] ?? null),
            raw_mfr_part_number: self::nullableString($data['raw_mfr_part_number'] ?? null),
            raw_description: trim((string) ($data['raw_description'] ?? '')),
            wholesale_price: (float) ($data['wholesale_price'] ?? 0),
            map_price: self::nullableFloat($data['map_price'] ?? null),
            msrp_price: self::nullableFloat($data['msrp_price'] ?? null),
            quantity_available: max($quantity, 0),
            is_in_stock: array_key_exists('is_in_stock', $data)
                ? (bool) $data['is_in_stock']
                : $quantity > 0,
            raw_payload: (array) ($data['raw_payload'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'distributor_sku' => $this->distributor_sku,
            'raw_upc' => $this->raw_upc,
            'raw_mfr_part_number' => $this->raw_mfr_part_number,
            'raw_description' => $this->raw_description,
            'wholesale_price' => $this->wholesale_price,
            'map_price' => $this->map_price,
            'msrp_price' => $this->msrp_price,
            'quantity_available' => $this->quantity_available,
            'is_in_stock' => $this->is_in_stock,
            'raw_payload' => $this->raw_payload,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
