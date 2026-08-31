<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for Davidson's.
 *
 * Transport: FTP. Payload: the Davidson's inventory CSV with a header
 * row — Item #, UPC, Description, Quantity, Price, Manufacturer. There
 * is no category column, so ammunition is identified from the
 * description.
 */
class DavidsonsFeedDriver extends AbstractFeedDriver
{
    protected function feedSlug(): string
    {
        return 'davidsons';
    }

    protected function defaultTransport(): string
    {
        return 'ftp';
    }

    protected function defaultRemotePath(): string
    {
        return 'davidsons-inventory.csv';
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $item = $this->pick($row, $columns, [
            'item', 'itemnumber', 'itemno', 'itemnbr', 'sku', 'stocknumber', 'davidsonsitem',
        ], 0);

        if ($item === null) {
            return null;
        }

        $description = $this->pick($row, $columns, [
            'description', 'itemdescription', 'longdescription', 'shortdescription',
        ], 2) ?? '';

        $manufacturer = $this->pick($row, $columns, [
            'manufacturer', 'mfg', 'mfgname', 'brand', 'manufacturername', 'mfr',
        ], 5);

        $category = $this->pick($row, $columns, ['category', 'department', 'class', 'type', 'producttype']);

        if (! $this->rowIsAmmunition($category, trim($description.' '.($manufacturer ?? '')))) {
            return null;
        }

        return FeedItemDTO::fromArray([
            'distributor_sku' => $item,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, ['upc', 'upccode', 'upcnumber'], 1)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'mpn', 'manufacturerpartnumber', 'mfgpartno', 'model', 'modelnumber', 'partnumber',
            ]),
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->pick($row, $columns, [
                'price', 'dealerprice', 'cost', 'dealercost', 'wholesale', 'wholesaleprice',
            ], 4)),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, ['map', 'mapprice', 'retailmap'])),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, ['msrp', 'retail', 'retailprice', 'srp'])),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'quantity', 'qty', 'quantityavailable', 'qtyavailable', 'onhand', 'available',
            ], 3)),
            'raw_payload' => [
                'manufacturer' => $manufacturer,
                'cells' => $row,
            ],
        ]);
    }
}
