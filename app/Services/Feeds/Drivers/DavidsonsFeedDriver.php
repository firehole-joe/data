<?php

namespace App\Services\Feeds\Drivers;

use App\Models\Distributor;
use App\Services\Ammunition\AmmoAttributeExtractor;
use App\Services\Ammunition\MasterRoundCountReconciler;
use App\Services\Feeds\AmmoPricingGuardrail;
use App\Services\Feeds\DTOs\FeedItemDTO;
use League\Flysystem\Filesystem;
use RuntimeException;

/**
 * Feed driver for Davidson's.
 *
 * Transport: SFTP on port 22 at `ftp.davidsons.com`. The account carries
 * two current-generation files that must be merged by `ItemNo`:
 *
 *  - `V2_Itemspec.csv` — the product catalog: specs, category, packaging
 *    and dealer/sale pricing, but no live quantity.
 *  - `V2_Qty.csv` — real-time on-hand quantity, keyed by `ItemNo`.
 *
 * The legacy `Itemspec.csv` / `Qty.csv` (no `V2_` prefix) are retired and
 * never requested.
 *
 * {@see downloadFeed()} pulls both files over one SFTP connection and
 * writes a single merged, comma-delimited temp file (the itemspec rows
 * plus one appended quantity column) so the rest of the pipeline —
 * {@see AbstractFeedDriver::parseFeed()} and {@see mapRow()} — works
 * exactly like every other driver's single-file feed.
 */
class DavidsonsFeedDriver extends AbstractFeedDriver
{
    /** SFTP port Davidson's listens on. */
    public const DEFAULT_PORT = 22;

    /** Current-generation catalog file. The legacy Itemspec.csv is retired. */
    public const ITEMSPEC_FILE = 'V2_Itemspec.csv';

    /** Current-generation quantity file. The legacy Qty.csv is retired. */
    public const QTY_FILE = 'V2_Qty.csv';

    /** Header name given to the quantity column appended during the merge. */
    private const MERGED_QTY_HEADER = 'DavidsonsMergedQtyOnHand';

    /** Header aliases (normalised) that identify the `ItemNo` column in either file. */
    private const ITEM_NO_ALIASES = ['itemno', 'item', 'itemnumber', 'itemnbr', 'sku'];

    /** Header aliases (normalised) that identify the on-hand quantity column in V2_Qty.csv. */
    private const QTY_ALIASES = ['qtyonhand', 'quantityonhand', 'qty', 'quantity', 'onhand', 'available'];

    public function __construct(
        private readonly MasterRoundCountReconciler $reconciler = new MasterRoundCountReconciler(
            new AmmoAttributeExtractor,
            new AmmoPricingGuardrail,
        ),
    ) {}

    protected function feedSlug(): string
    {
        return 'davidsons';
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
        return self::ITEMSPEC_FILE;
    }

    /** @return array<int, string> */
    protected function delimiterCandidates(): array
    {
        return [','];
    }

    /**
     * Pull `V2_Itemspec.csv` and `V2_Qty.csv` over one SFTP connection and
     * merge them into a single local file keyed by `ItemNo`.
     */
    public function downloadFeed(Distributor $distributor): string
    {
        $settings = $this->settingsFor($distributor);
        $filesystem = $this->sftpFilesystem($settings);

        $itemspecRemote = $this->remoteFileName($settings, 'itemspec_path', self::ITEMSPEC_FILE);
        $qtyRemote = $this->remoteFileName($settings, 'qty_path', self::QTY_FILE);

        $itemspecLocal = null;
        $qtyLocal = null;

        try {
            $itemspecLocal = $this->downloadOne($filesystem, $itemspecRemote, 'davidsons_itemspec_');
            $qtyLocal = $this->downloadOne($filesystem, $qtyRemote, 'davidsons_qty_');

            $merged = $this->mergeItemspecWithQuantities($itemspecLocal, $qtyLocal);

            if ((@filesize($merged) ?: 0) === 0) {
                @unlink($merged);
                throw new RuntimeException("Merged {$this->feedSlug()} feed is empty.");
            }

            return $merged;
        } finally {
            if ($itemspecLocal !== null && is_file($itemspecLocal)) {
                @unlink($itemspecLocal);
            }
            if ($qtyLocal !== null && is_file($qtyLocal)) {
                @unlink($qtyLocal);
            }
        }
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $itemNo = $this->pick($row, $columns, self::ITEM_NO_ALIASES, 0);
        if ($itemNo === null) {
            return null;
        }

        $ammoCat = $this->pick($row, $columns, ['ammocat']);
        $ammoCaliber = $this->pick($row, $columns, ['ammocaliber', 'caliber']);
        $ammoDesc = $this->pick($row, $columns, ['ammodesc1']);
        $prodCat = $this->pick($row, $columns, ['prodcat']);
        $category = $this->pick($row, $columns, ['category']);
        $subcategory = $this->pick($row, $columns, ['subcategory']);

        $description = $this->davidsonsDescription($row, $columns, $ammoDesc);

        if (! $this->rowIsDavidsonsAmmo($ammoCat, $ammoCaliber, $ammoDesc, $prodCat, $category, $subcategory, $description)) {
            return null;
        }

        $brand = $this->pick($row, $columns, ['brand']);
        $bulletWeight = $this->pick($row, $columns, ['bulletweight']);
        $bulletType = $this->pick($row, $columns, ['bullettype']);

        $dealerPrice = $this->toFloat($this->pick($row, $columns, ['dealerprice']));
        $salePrice = $this->toNullableFloat($this->pick($row, $columns, ['saleprice']));
        // "Use sale price if present and > 0, otherwise DealerPrice" — and
        // never let a mispriced sale exceed the dealer cost.
        $wholesalePrice = $salePrice !== null ? min($salePrice, $dealerPrice) : $dealerPrice;

        $roundPerBoxRaw = $this->pick($row, $columns, ['roundperbox']);
        $boxPerCaseRaw = $this->pick($row, $columns, ['boxpercase']);

        return FeedItemDTO::fromArray([
            'distributor_sku' => $itemNo,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, ['upc'], 1)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'mpn', 'manufacturerpartnumber', 'mfgpartno', 'model', 'modelnumber', 'partnumber',
            ]),
            'raw_description' => $description,
            'wholesale_price' => $wholesalePrice,
            'map_price' => null,
            'msrp_price' => null,
            'quantity_available' => $this->toInt($this->pick($row, $columns, [self::MERGED_QTY_HEADER])),
            'raw_round_count' => $this->resolveRoundsPerUnit(
                $roundPerBoxRaw,
                $boxPerCaseRaw,
                $this->pick($row, $columns, ['qtypercase']),
                $itemNo,
                $description,
                $wholesalePrice,
                $ammoCaliber,
            ),
            'raw_payload' => [
                'brand' => $brand,
                'ammo_cat' => $ammoCat,
                'ammo_caliber' => $ammoCaliber,
                'bullet_weight' => $bulletWeight,
                'bullet_type' => $bulletType,
                // Kept verbatim for debugging; `distributor_products` has
                // no raw-payload column, so this does not persist past the
                // DTO — a later repair re-derives the count from
                // raw_description / the master instead, not from here.
                'round_per_box' => $roundPerBoxRaw,
                'box_per_case' => $boxPerCaseRaw,
                'cells' => $row,
            ],
        ]);
    }

    /**
     * `ItemDesc1` + `ItemDesc2`, falling back to the more specific
     * `AmmoDesc1` when both are blank (Hornady's ammo-only rows carry
     * detail only in `AmmoDesc1`).
     *
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    private function davidsonsDescription(array $row, array $columns, ?string $ammoDesc): string
    {
        $desc1 = (string) ($this->pick($row, $columns, ['itemdesc1']) ?? '');
        $desc2 = (string) ($this->pick($row, $columns, ['itemdesc2']) ?? '');

        $combined = trim($desc1.' '.$desc2);

        return $combined !== '' ? $combined : (string) ($ammoDesc ?? '');
    }

    /**
     * Davidson's ammunition filter:
     *
     *  1. `AmmoCat`, `AmmoCaliber` or `AmmoDesc1` populated is a direct
     *     signal — those columns are only ever filled in for ammunition;
     *  2. otherwise fall back to the general `ProdCat` / `Category` /
     *     `Subcategory` blob (and, failing that, the description)
     *     through the shared category / description heuristics.
     */
    private function rowIsDavidsonsAmmo(
        ?string $ammoCat,
        ?string $ammoCaliber,
        ?string $ammoDesc,
        ?string $prodCat,
        ?string $category,
        ?string $subcategory,
        string $description,
    ): bool {
        foreach ([$ammoCat, $ammoCaliber, $ammoDesc] as $signal) {
            if ($signal !== null && trim($signal) !== '') {
                return true;
            }
        }

        $generalCategory = trim(implode(' ', array_filter([$prodCat, $category, $subcategory])));

        return $this->rowIsAmmunition($generalCategory !== '' ? $generalCategory : null, $description);
    }

    /**
     * The true rounds-per-unit for THIS row's own `DealerPrice`.
     *
     * `DealerPrice` prices a single retail box — `round_per_box` is
     * therefore the authoritative count for a normal row and is trusted
     * directly. `box_per_case` is shipping/carton packaging metadata
     * (how many boxes fit in a master carton) and is **never** used as a
     * multiplier just because it is present and greater than 1 — doing
     * so was the bug: a $13.50 / 50-round box with `box_per_case = 10`
     * was getting stamped `round_count = 500`, computing ~$0.027/rd and
     * getting flagged as a bogus case-count-as-box-count parse.
     *
     * The count is only multiplied by `box_per_case` when this specific
     * row is itself a genuine case-level SKU — its own SKU/description
     * says so ("Case", "CS", "Case Pack", ...), or its price clears the
     * case-pricing threshold for a centerfire pistol/rifle cartridge —
     * in which case `DealerPrice` really is priced per case and
     * `round_per_box * box_per_case` reconstructs the case's true round
     * count. That judgment reuses
     * {@see MasterRoundCountReconciler::isCasePack()} so "is this a
     * case" is decided the same way everywhere in the pipeline.
     */
    private function resolveRoundsPerUnit(
        ?string $roundPerBoxRaw,
        ?string $boxPerCaseRaw,
        ?string $qtyPerCaseRaw,
        string $itemNo,
        string $description,
        float $wholesalePrice,
        ?string $caliber,
    ): ?int {
        $roundPerBox = $this->toNullableFloat($roundPerBoxRaw);

        if ($roundPerBox !== null) {
            $boxPerCase = $this->toNullableFloat($boxPerCaseRaw);

            $isCaseLevelSku = $boxPerCase !== null && $boxPerCase > 1
                && $this->reconciler->isCasePack($itemNo, $description, $wholesalePrice, $caliber);

            return $isCaseLevelSku
                ? (int) round($roundPerBox * $boxPerCase)
                : (int) round($roundPerBox);
        }

        // No per-box figure at all: QtyPerCase is sometimes the only
        // packaging cue Davidson's supplies for a case-level SKU.
        $qtyPerCase = $this->toNullableFloat($qtyPerCaseRaw);

        return $qtyPerCase !== null ? (int) round($qtyPerCase) : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function remoteFileName(array $settings, string $settingKey, string $default): string
    {
        $value = trim((string) ($settings[$settingKey] ?? ''));

        return $value !== '' ? $value : $default;
    }

    private function downloadOne(Filesystem $filesystem, string $remotePath, string $prefix): string
    {
        $local = tempnam(sys_get_temp_dir(), $prefix);
        if ($local === false) {
            throw new RuntimeException("Unable to allocate a temporary file for [{$remotePath}].");
        }

        $this->streamToFile($filesystem->readStream($remotePath), $local);

        return $local;
    }

    /**
     * Left-join `V2_Qty.csv` onto `V2_Itemspec.csv` by `ItemNo`, writing
     * a new comma-delimited temp file with one appended quantity column.
     * An `ItemNo` absent from the quantity file is treated as 0 on hand
     * rather than dropped — Davidson's still lists the catalog entry.
     *
     * `public`, and named for what it does rather than how
     * {@see downloadFeed()} uses it, so the merge-by-ItemNo behaviour can
     * be exercised directly against local fixtures in tests without any
     * SFTP transport involved. The caller owns the returned temp file.
     */
    public function mergeItemspecWithQuantities(string $itemspecPath, string $qtyPath): string
    {
        $quantities = $this->readQuantityMap($qtyPath);

        $mergedPath = tempnam(sys_get_temp_dir(), 'davidsons_merged_');
        if ($mergedPath === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the merged Davidson\'s feed.');
        }

        $in = fopen($itemspecPath, 'rb');
        $out = fopen($mergedPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Unable to merge the Davidson\'s feed files.');
        }

        try {
            $lineNumber = 0;
            $itemNoIndex = null;

            while (($line = fgets($in)) !== false) {
                $lineNumber++;
                $line = rtrim($line, "\r\n");

                if ($lineNumber === 1) {
                    $line = ltrim($line, "\u{FEFF}");
                }

                if ($line === '') {
                    continue;
                }

                $cells = str_getcsv($line, ',', '"', '\\');

                if ($lineNumber === 1) {
                    $itemNoIndex = $this->findColumn($cells, self::ITEM_NO_ALIASES);
                    $cells[] = self::MERGED_QTY_HEADER;
                } else {
                    $itemNo = $itemNoIndex !== null ? strtoupper(trim((string) ($cells[$itemNoIndex] ?? ''))) : '';
                    $cells[] = (string) ($quantities[$itemNo] ?? 0);
                }

                fputcsv($out, $cells);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $mergedPath;
    }

    /**
     * @return array<string, int> ItemNo (uppercased) => quantity on hand.
     */
    private function readQuantityMap(string $qtyPath): array
    {
        $handle = fopen($qtyPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open the Davidson's quantity feed [{$qtyPath}].");
        }

        $map = [];

        try {
            $lineNumber = 0;
            $itemNoIndex = null;
            $qtyIndex = null;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = rtrim($line, "\r\n");

                if ($lineNumber === 1) {
                    $line = ltrim($line, "\u{FEFF}");
                }

                if ($line === '') {
                    continue;
                }

                $cells = str_getcsv($line, ',', '"', '\\');

                if ($lineNumber === 1) {
                    $itemNoIndex = $this->findColumn($cells, self::ITEM_NO_ALIASES);
                    $qtyIndex = $this->findColumn($cells, self::QTY_ALIASES);

                    continue;
                }

                if ($itemNoIndex === null || $qtyIndex === null) {
                    continue;
                }

                $itemNo = strtoupper(trim((string) ($cells[$itemNoIndex] ?? '')));
                if ($itemNo === '') {
                    continue;
                }

                $map[$itemNo] = $this->toInt($cells[$qtyIndex] ?? '0');
            }
        } finally {
            fclose($handle);
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $headerCells
     * @param  array<int, string>  $aliases
     */
    private function findColumn(array $headerCells, array $aliases): ?int
    {
        foreach ($headerCells as $index => $name) {
            if (in_array($this->normalizeKey($name), $aliases, true)) {
                return $index;
            }
        }

        return null;
    }
}
