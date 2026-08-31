<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for 2nd Amendment Wholesale.
 *
 * Transport: REST API (HTTP with Bearer or Basic auth). Payload: a JSON
 * product array (`sku`, `upc`, `mpn`, `name`, `wholesale_price`,
 * `quantity`, `category`); a CSV export with the same fields is also
 * supported.
 */
class SecondAmendmentFeedDriver extends AbstractFeedDriver
{
    private const CATEGORY_KEYS = ['category', 'department', 'type', 'product_type', 'class', 'group'];

    protected function feedSlug(): string
    {
        return 'second_amendment';
    }

    protected function defaultTransport(): string
    {
        return 'rest_api';
    }

    protected function defaultRemotePath(): string
    {
        return 'v1/products';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function mapJsonRow(array $item): ?FeedItemDTO
    {
        $sku = $this->jsonValue($item, ['sku', 'item_number', 'id', 'product_id', 'productsku']);
        if ($sku === null) {
            return null;
        }

        $name = (string) ($this->jsonValue($item, ['name', 'description', 'title', 'product_name']) ?? '');
        $category = $this->jsonValue($item, self::CATEGORY_KEYS);
        $categoryText = is_string($category) ? $category : null;

        if (! $this->rowIsAmmunition($categoryText, $name)) {
            return null;
        }

        $mpn = $this->jsonValue($item, ['mpn', 'manufacturer_part_number', 'mfg_part_number', 'mfr_part_number', 'model', 'part_number']);

        return FeedItemDTO::fromArray([
            'distributor_sku' => (string) $sku,
            'raw_upc' => $this->cleanUpc((string) ($this->jsonValue($item, ['upc', 'upc_code', 'gtin', 'barcode']) ?? '')),
            'raw_mfr_part_number' => $mpn !== null ? (string) $mpn : null,
            'raw_description' => $name,
            'wholesale_price' => $this->toFloat($this->jsonValue($item, [
                'wholesale_price', 'dealer_price', 'dealer_cost', 'price', 'cost', 'your_price',
            ])),
            'map_price' => $this->toNullableFloat($this->jsonValue($item, ['map', 'map_price', 'minimum_advertised_price'])),
            'msrp_price' => $this->toNullableFloat($this->jsonValue($item, ['msrp', 'retail', 'retail_price', 'srp'])),
            'quantity_available' => $this->toInt($this->jsonValue($item, [
                'quantity', 'qty', 'quantity_available', 'qty_available', 'stock', 'available', 'in_stock', 'inventory',
            ])),
            'raw_payload' => [
                'category' => $categoryText,
                'source' => 'json',
            ],
        ]);
    }

    /**
     * CSV fallback for the same feed.
     *
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $sku = $this->pick($row, $columns, ['sku', 'itemnumber', 'item', 'itemno', 'productid'], 0);
        if ($sku === null) {
            return null;
        }

        $description = $this->pick($row, $columns, ['name', 'description', 'itemdescription', 'title'], 3) ?? '';
        $category = $this->pick($row, $columns, ['category', 'department', 'type', 'producttype', 'class'], 6);

        if (! $this->rowIsAmmunition($category, $description)) {
            return null;
        }

        return FeedItemDTO::fromArray([
            'distributor_sku' => $sku,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, ['upc', 'upccode', 'gtin', 'barcode'], 1)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'mpn', 'manufacturerpartnumber', 'mfgpartnumber', 'model', 'partnumber',
            ], 2),
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->pick($row, $columns, [
                'wholesaleprice', 'dealerprice', 'dealercost', 'price', 'cost',
            ], 4)),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, ['map', 'mapprice'])),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, ['msrp', 'retail', 'retailprice', 'srp'])),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'quantity', 'qty', 'quantityavailable', 'qtyavailable', 'stock', 'available',
            ], 5)),
            'raw_payload' => [
                'category' => $category,
                'source' => 'csv',
            ],
        ]);
    }
}
