<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for Zanders Sporting Goods.
 *
 * Transport: plain FTP on port 21 at `ftp2.gzanders.com`. Payload:
 * `Inventory/zandersinv.csv` — a comma-delimited export with a header
 * row whose columns are Avail, Category, Desc1, Desc2, Item#, MFG,
 * MFGPNum, MSRP, Price1-3, Qty1-3, UPC, Weight, Serialized. Only rows
 * whose `Category` reads as ammunition are kept; `Desc1` and `Desc2`
 * are joined into the description and `Price1` / `Avail` drive the
 * wholesale price and quantity.
 *
 * The older header names from the legacy HTTP-CSV export (Item Number,
 * Description, Price, Quantity Available) are still accepted as aliases.
 */
class ZandersFeedDriver extends AbstractFeedDriver
{
    /** FTP control port. */
    public const DEFAULT_PORT = 21;

    /** Catalog file, refreshed by the vendor through the day. */
    public const CATALOG_FILE = 'Inventory/zandersinv.csv';

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
        return 'ftp';
    }

    protected function defaultPort(): ?int
    {
        return self::DEFAULT_PORT;
    }

    protected function defaultSsl(): bool
    {
        return false;
    }

    protected function defaultRemotePath(): string
    {
        return self::CATALOG_FILE;
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
        // `Item#` normalises to "item"; the generic aliases keep the
        // legacy export layout parsing too.
        $itemNumber = $this->pick($row, $columns, [
            'item', 'itemnumber', 'itemno', 'itemnbr', 'sku', 'stocknumber',
        ], 4);

        if ($itemNumber === null) {
            return null;
        }

        $description = $this->zandersDescription($row, $columns);
        $category = $this->pick($row, $columns, self::CATEGORY_ALIASES, 1);
        $manufacturer = $this->pick($row, $columns, ['mfg', 'manufacturer', 'brand', 'mfgname']);

        if (! $this->rowIsAmmunition($category, trim(($manufacturer ?? '').' '.$description))) {
            return null;
        }

        return FeedItemDTO::fromArray([
            'distributor_sku' => $itemNumber,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, [
                'upc', 'upccode', 'upcean', 'upcnumber',
            ], 14)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'mfgpnum', 'mfgpartnum', 'mpn', 'manufacturerpartnumber', 'mfgpart',
                'mfgpartno', 'model', 'modelnumber', 'partnumber',
            ], 6),
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->pick($row, $columns, [
                'price1', 'price', 'dealerprice', 'cost', 'wholesale', 'wholesaleprice', 'yourprice', 'dealercost',
            ], 8)),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, ['map', 'mapprice'])),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, [
                'msrp', 'retail', 'retailprice', 'srp',
            ], 7)),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'avail', 'quantityavailable', 'quantity', 'qtyavailable', 'qty', 'qty1', 'available', 'stock', 'onhand',
            ], 0)),
            'raw_payload' => [
                'category' => $category,
                'manufacturer' => $manufacturer,
                'cells' => $row,
            ],
        ]);
    }

    /**
     * `Desc1` + `Desc2` trimmed and joined; falls back to a single
     * description column for the legacy export layout.
     *
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    private function zandersDescription(array $row, array $columns): string
    {
        $desc1 = (string) $this->pick($row, $columns, ['desc1', 'description1']);
        $desc2 = (string) $this->pick($row, $columns, ['desc2', 'description2']);

        $combined = trim(trim($desc1).' '.trim($desc2));

        if ($combined !== '') {
            return $combined;
        }

        return $this->pick($row, $columns, [
            'description', 'itemdescription', 'longdescription', 'shortdescription',
        ], 2) ?? '';
    }
}
