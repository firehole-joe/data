<?php

namespace App\Services\Feeds\Drivers;

use App\Services\Feeds\DTOs\FeedItemDTO;

/**
 * Feed driver for RSR Group.
 *
 * RSR serves an SFTP account at `ftps.rsrgroup.com:2222`. The primary
 * catalog is `rsrinventory-new.txt` — a semicolon-delimited, header-less,
 * fixed-column file (~77 columns) refreshed every two hours that carries
 * SKU, UPC, description, dealer price, inventory qty, MAP and department.
 * A companion `IM-QTY-CSV.csv` delta refreshes every five minutes; point
 * `connection_settings.remote_path` at it for near-real-time quantity
 * pulls. Only Department 18 (Ammunition) rows are kept.
 */
class RsrFeedDriver extends AbstractFeedDriver
{
    /** SFTP port RSR listens on. */
    public const DEFAULT_PORT = 2222;

    /** Primary catalog file, updated every two hours. */
    public const CATALOG_FILE = 'rsrinventory-new.txt';

    /** Fast quantity delta file, updated every five minutes. */
    public const QUANTITY_DELTA_FILE = 'IM-QTY-CSV.csv';

    /** Zero-based column positions in the RSR inventory file. */
    private const COL_STOCK_NUMBER = 0;

    private const COL_UPC = 1;

    private const COL_DESCRIPTION = 2;

    private const COL_DEPARTMENT = 3;

    private const COL_MSRP_PRICE = 5;

    private const COL_WHOLESALE_PRICE = 6;

    private const COL_QUANTITY = 8;

    private const COL_MANUFACTURER = 10;

    private const COL_MFR_PART_NUMBER = 11;

    private const COL_EXPANDED_DESCRIPTION = 13;

    private const COL_MAP_PRICE = 69;

    /** RSR department numbers that correspond to ammunition. */
    private const AMMUNITION_DEPARTMENTS = ['18'];

    protected function feedSlug(): string
    {
        return 'rsr';
    }

    protected function defaultTransport(): string
    {
        return 'sftp';
    }

    protected function defaultPort(): ?int
    {
        return self::DEFAULT_PORT;
    }

    protected function defaultRemotePath(): string
    {
        return self::CATALOG_FILE;
    }

    protected function expectsHeaderRow(): bool
    {
        return false;
    }

    /** @return array<int, string> */
    protected function delimiterCandidates(): array
    {
        return [';', "\t", '|'];
    }

    /**
     * RSR fields are never quoted but descriptions can contain stray
     * double quotes ("1.5\" barrel"), so a plain explode is safer here
     * than the CSV-aware default.
     *
     * @return array<int, string>
     */
    protected function splitLine(string $line, string $delimiter): array
    {
        return array_map('trim', explode($delimiter, $line));
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $department = isset($row[self::COL_DEPARTMENT]) ? trim((string) $row[self::COL_DEPARTMENT]) : '';

        // A populated department outside the ammunition list is dropped;
        // a blank department is passed through for later matching.
        if ($department !== '' && ! in_array($department, self::AMMUNITION_DEPARTMENTS, true)) {
            return null;
        }

        $sku = isset($row[self::COL_STOCK_NUMBER]) ? trim((string) $row[self::COL_STOCK_NUMBER]) : '';
        if ($sku === '') {
            return null;
        }

        return FeedItemDTO::fromArray([
            'distributor_sku' => $sku,
            'raw_upc' => $this->cleanUpc($row[self::COL_UPC] ?? null),
            'raw_mfr_part_number' => $row[self::COL_MFR_PART_NUMBER] ?? null,
            'raw_description' => $this->bestDescription($row),
            'wholesale_price' => $this->toFloat($row[self::COL_WHOLESALE_PRICE] ?? null),
            'map_price' => $this->toNullableFloat($row[self::COL_MAP_PRICE] ?? null),
            'msrp_price' => $this->toNullableFloat($row[self::COL_MSRP_PRICE] ?? null),
            'quantity_available' => $this->toInt($row[self::COL_QUANTITY] ?? null),
            'raw_payload' => [
                'department' => $row[self::COL_DEPARTMENT] ?? null,
                'manufacturer' => $row[self::COL_MANUFACTURER] ?? null,
                'fields' => $row,
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $row
     */
    private function bestDescription(array $row): string
    {
        $expanded = trim((string) ($row[self::COL_EXPANDED_DESCRIPTION] ?? ''));
        $short = trim((string) ($row[self::COL_DESCRIPTION] ?? ''));

        return $expanded !== '' ? $expanded : $short;
    }
}
