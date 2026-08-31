<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for Zanders Sporting Goods.
 *
 * Transport: HTTP CSV download. Payload: a standard CSV with a header
 * row — Item Number, UPC, Description, Price, Quantity Available and a
 * Category column that is filtered down to the ammo classes.
 */
class ZandersFeedDriver extends AbstractFeedDriver
{
    private const CATEGORY_ALIASES = [
        'category', 'categoryname', 'categorydescription', 'department',
        'class', 'producttype', 'itemcategory', 'productcategory',
    ];

    protected function feedSlug(): string
    {
        return 'zanders';
    }

    protected function defaultTransport(): string
    {
        return 'http_csv';
    }

    protected function defaultRemotePath(): string
    {
        return 'zanders-inventory.csv';
    }

    /** @return array<int, string> */
    protected function delimiterCandidates(): array
    {
        return [',', "\t", ';', '|'];
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $itemNumber = $this->pick($row, $columns, [
            'itemnumber', 'itemno', 'item', 'itemnbr', 'sku', 'stocknumber',
        ], 0);

        if ($itemNumber === null) {
            return null;
        }

        $description = $this->pick($row, $columns, [
            'description', 'itemdescription', 'longdescription', 'shortdescription',
        ], 2) ?? '';

        $category = $this->pick($row, $columns, self::CATEGORY_ALIASES, 5);

        if (! $this->rowIsAmmunition($category, $description)) {
            return null;
        }

        return FeedItemDTO::fromArray([
            'distributor_sku' => $itemNumber,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, ['upc', 'upccode', 'upcean', 'upcnumber'], 1)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'mpn', 'manufacturerpartnumber', 'mfgpart', 'mfgpartno', 'model', 'modelnumber', 'partnumber',
            ]),
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->pick($row, $columns, [
                'price', 'dealerprice', 'cost', 'wholesale', 'wholesaleprice', 'yourprice', 'dealercost',
            ], 3)),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, ['map', 'mapprice'])),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, ['msrp', 'retail', 'retailprice', 'srp'])),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'quantityavailable', 'quantity', 'qtyavailable', 'qty', 'available', 'stock', 'onhand',
            ], 4)),
            'raw_payload' => [
                'category' => $category,
                'cells' => $row,
            ],
        ]);
    }
}
