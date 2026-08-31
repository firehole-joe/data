<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for Chattanooga Shooting Supplies.
 *
 * Transport: FTP. Payload: a pipe-delimited (occasionally comma) export
 * with a header row — Chattanooga SKU, UPC, Item Description,
 * Caliber/Gauge, Qty Available, Dealer Price, MAP and a category column
 * used to keep only ammunition.
 */
class ChattanoogaFeedDriver extends AbstractFeedDriver
{
    private const CATEGORY_ALIASES = [
        'category', 'categorydescription', 'categoryname', 'department',
        'departmentdescription', 'class', 'itemcategory', 'producttype',
    ];

    protected function feedSlug(): string
    {
        return 'chattanooga';
    }

    protected function defaultTransport(): string
    {
        return 'ftp';
    }

    protected function defaultRemotePath(): string
    {
        return 'inventory.txt';
    }

    /** @return array<int, string> */
    protected function delimiterCandidates(): array
    {
        return ['|', ',', "\t", ';'];
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $sku = $this->pick($row, $columns, [
            'csku', 'chattanoogasku', 'sku', 'itemnumber', 'itemno', 'item', 'stocknumber',
        ], 0);

        if ($sku === null) {
            return null;
        }

        $description = $this->pick($row, $columns, [
            'itemdescription', 'description', 'shortdescription', 'itemdesc', 'longdescription',
        ], 2) ?? '';

        $caliber = $this->pick($row, $columns, ['calibergauge', 'caliber', 'gauge'], 3);
        $category = $this->pick($row, $columns, self::CATEGORY_ALIASES, 7);

        if (! $this->rowIsAmmunition($category, trim($description.' '.($caliber ?? '')))) {
            return null;
        }

        return FeedItemDTO::fromArray([
            'distributor_sku' => $sku,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, ['upc', 'upccode', 'upcnumber'], 1)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'mfgpartno', 'manufacturerpartnumber', 'mfrpartnumber', 'mpn', 'manufacturersku', 'modelnumber', 'model',
            ]),
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->pick($row, $columns, [
                'dealerprice', 'price', 'cost', 'yourprice', 'wholesaleprice', 'dealercost',
            ], 5)),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, [
                'map', 'mapprice', 'minimumadvertisedprice',
            ], 6)),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, [
                'msrp', 'retail', 'retailprice', 'srp',
            ])),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'qtyavailable', 'quantityavailable', 'quantity', 'qty', 'available', 'onhand', 'qtyonhand',
            ], 4)),
            'raw_payload' => [
                'caliber' => $caliber,
                'category' => $category,
                'cells' => $row,
            ],
        ]);
    }
}
