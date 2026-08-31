<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for Crow Shooting Supply.
 *
 * Transport: FTP. Payload: a flat CSV with a header row — Item, UPC,
 * Description, Cost, Quantity, Dept. The Dept column drives the
 * ammunition filter.
 */
class CrowFeedDriver extends AbstractFeedDriver
{
    private const DEPT_ALIASES = [
        'dept', 'department', 'departmentname', 'departmentdescription',
        'category', 'class', 'deptdescription',
    ];

    protected function feedSlug(): string
    {
        return 'crow';
    }

    protected function defaultTransport(): string
    {
        return 'ftp';
    }

    protected function defaultRemotePath(): string
    {
        return 'crow-inventory.csv';
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $item = $this->pick($row, $columns, [
            'item', 'itemnumber', 'itemno', 'sku', 'stocknumber', 'crowitem',
        ], 0);

        if ($item === null) {
            return null;
        }

        $description = $this->pick($row, $columns, [
            'description', 'itemdescription', 'longdescription', 'shortdescription',
        ], 2) ?? '';

        $dept = $this->pick($row, $columns, self::DEPT_ALIASES, 5);

        if (! $this->rowIsAmmunition($dept, $description)) {
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
                'cost', 'dealercost', 'price', 'dealerprice', 'wholesale', 'wholesaleprice',
            ], 3)),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, ['map', 'mapprice'])),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, ['msrp', 'retail', 'retailprice', 'srp'])),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'quantity', 'qty', 'quantityavailable', 'qtyavailable', 'onhand', 'available', 'stock',
            ], 4)),
            'raw_payload' => [
                'dept' => $dept,
                'cells' => $row,
            ],
        ]);
    }
}
