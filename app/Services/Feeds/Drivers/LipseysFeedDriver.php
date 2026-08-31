<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for Lipsey's.
 *
 * Transport: SFTP. Payload: the Lipsey's catalog/inventory CSV with a
 * header row — ItemNumber, UPC, Description, Caliber, Quantity, Price,
 * MAP, Manufacturer, ManufacturerPartNumber. Lipsey's is largely a
 * firearms distributor, so rows are kept only when the description reads
 * as loaded ammunition.
 */
class LipseysFeedDriver extends AbstractFeedDriver
{
    protected function feedSlug(): string
    {
        return 'lipseys';
    }

    protected function defaultTransport(): string
    {
        return 'sftp';
    }

    protected function defaultRemotePath(): string
    {
        return 'lipseys-inventory.csv';
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $itemNumber = $this->pick($row, $columns, [
            'itemnumber', 'itemno', 'item', 'sku', 'lipseysitemnumber', 'stocknumber',
        ], 0);

        if ($itemNumber === null) {
            return null;
        }

        $description = $this->pick($row, $columns, [
            'description', 'itemdescription', 'longdescription', 'shortdescription',
        ], 2) ?? '';

        $caliber = $this->pick($row, $columns, ['caliber', 'calibergauge', 'gauge', 'calibredescription'], 3);
        $category = $this->pick($row, $columns, ['category', 'type', 'itemtype', 'class', 'department']);

        if (! $this->rowIsAmmunition($category, trim($description.' '.($caliber ?? '')))) {
            return null;
        }

        return FeedItemDTO::fromArray([
            'distributor_sku' => $itemNumber,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, ['upc', 'upccode', 'upcnumber'], 1)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'manufacturerpartnumber', 'mfgpartnumber', 'mpn', 'model', 'modelnumber', 'partnumber',
            ], 8),
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->pick($row, $columns, [
                'price', 'dealerprice', 'cost', 'wholesale', 'wholesaleprice', 'yourprice',
            ], 5)),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, [
                'map', 'mapprice', 'retailmap', 'minimumadvertisedprice',
            ], 6)),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, ['msrp', 'retail', 'retailprice', 'srp'])),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'quantity', 'qty', 'quantityavailable', 'qtyavailable', 'onhand', 'available',
            ], 4)),
            'raw_payload' => [
                'caliber' => $caliber,
                'manufacturer' => $this->pick($row, $columns, ['manufacturer', 'mfg', 'brand', 'manufacturername'], 7),
                'cells' => $row,
            ],
        ]);
    }
}
