<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for Primary Arms Wholesale.
 *
 * Transport: REST API / CSV endpoint. Payload: SKU, UPC, MPN,
 * Description, InStock Qty, Dealer Cost — served either as a CSV export
 * or a JSON product array. Optics and parts dominate the catalog, so
 * ammunition is identified from the description.
 */
class PrimaryArmsFeedDriver extends AbstractFeedDriver
{
    private const CATEGORY_KEYS = ['category', 'department', 'type', 'product_type', 'class', 'family'];

    protected function feedSlug(): string
    {
        return 'primary_arms';
    }

    protected function defaultTransport(): string
    {
        return 'rest_api';
    }

    protected function defaultRemotePath(): string
    {
        return 'wholesale/feed.csv';
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $sku = $this->pick($row, $columns, [
            'sku', 'itemnumber', 'item', 'itemno', 'productsku', 'partnumber',
        ], 0);

        if ($sku === null) {
            return null;
        }

        $description = $this->pick($row, $columns, [
            'description', 'name', 'productname', 'itemdescription', 'longdescription',
        ], 3) ?? '';

        $category = $this->pick($row, $columns, self::CATEGORY_KEYS);

        if (! $this->rowIsAmmunition($category, $description)) {
            return null;
        }

        return FeedItemDTO::fromArray([
            'distributor_sku' => $sku,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, ['upc', 'upccode', 'gtin'], 1)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'mpn', 'manufacturerpartnumber', 'model', 'modelnumber', 'partnumber',
            ], 2),
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->pick($row, $columns, [
                'dealercost', 'dealerprice', 'cost', 'price', 'wholesale', 'wholesaleprice', 'yourprice',
            ], 5)),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, ['map', 'mapprice'])),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, ['msrp', 'retail', 'retailprice', 'srp'])),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'instockqty', 'instock', 'quantity', 'qty', 'qtyavailable', 'quantityavailable', 'stock', 'available', 'onhand',
            ], 4)),
            'raw_payload' => [
                'category' => $category,
                'cells' => $row,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function mapJsonRow(array $item): ?FeedItemDTO
    {
        $sku = $this->jsonValue($item, ['sku', 'item_number', 'id', 'product_sku', 'part_number']);
        if ($sku === null) {
            return null;
        }

        $description = (string) ($this->jsonValue($item, ['description', 'name', 'product_name', 'title']) ?? '');
        $category = $this->jsonValue($item, self::CATEGORY_KEYS);
        $categoryText = is_string($category) ? $category : null;

        if (! $this->rowIsAmmunition($categoryText, $description)) {
            return null;
        }

        $mpn = $this->jsonValue($item, ['mpn', 'manufacturer_part_number', 'model', 'part_number']);

        return FeedItemDTO::fromArray([
            'distributor_sku' => (string) $sku,
            'raw_upc' => $this->cleanUpc((string) ($this->jsonValue($item, ['upc', 'upc_code', 'gtin']) ?? '')),
            'raw_mfr_part_number' => $mpn !== null ? (string) $mpn : null,
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->jsonValue($item, [
                'dealer_cost', 'dealer_price', 'cost', 'price', 'wholesale_price',
            ])),
            'map_price' => $this->toNullableFloat($this->jsonValue($item, ['map', 'map_price'])),
            'msrp_price' => $this->toNullableFloat($this->jsonValue($item, ['msrp', 'retail', 'retail_price', 'srp'])),
            'quantity_available' => $this->toInt($this->jsonValue($item, [
                'instock_qty', 'in_stock_qty', 'in_stock', 'instock', 'quantity', 'qty', 'quantity_available', 'stock', 'available',
            ])),
            'raw_payload' => [
                'category' => $categoryText,
                'source' => 'json',
            ],
        ]);
    }
}
