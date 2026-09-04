<?php

namespace App\Services\Feeds\Drivers;

use App\Models\Distributor;
use App\Services\Ammunition\AmmoAttributeExtractor;
use App\Services\Feeds\DTOs\FeedItemDTO;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Feed driver for Chattanooga Shooting Supplies (CSSI).
 *
 * Transport: the Chattanooga REST API (v6). Authentication is a custom
 * header that only looks like HTTP Basic — the account SID and the MD5
 * digest of the API token are sent as a literal, un-encoded string:
 *
 *     Authorization: Basic {SID}:{md5(TOKEN)}
 *
 * base64-encoding the pair or using withBasicAuth() returns
 * `401 Bad Authorization`.
 *
 * Retrieval is two-legged. `GET /items/product-feed` asks Chattanooga to
 * generate a fresh inventory export and answers with
 * `{ "product_feed": { "url": "https://..." } }`; the driver then
 * stream-downloads that CSV into a temp file for the shared parser.
 *
 * The export (itemInventory.csv) is comma-delimited with a header row:
 * CSSI Item Number, UPC Code, Manufacturer Item Number, Manufacturer,
 * Item Description (with Web Item Name / Web Item Description fallbacks),
 * Category, Price, MAP Price, MSRP, Qty On Hand.
 *
 * Many genuine ammunition rows ship with an empty Category, so a blank
 * or ambiguous category falls back to caliber detection on the
 * description: the row is kept only when {@see AmmoAttributeExtractor}
 * recovers a caliber.
 */
class ChattanoogaFeedDriver extends AbstractFeedDriver
{
    /** Default REST base, overridable via connection_settings.base_uri. */
    public const API_BASE_URL = 'https://api.chattanoogashooting.com/rest/v6/';

    /** Endpoint that generates and locates the inventory export. */
    public const PRODUCT_FEED_ENDPOINT = 'items/product-feed';

    /** Category tokens that positively identify an ammunition row. */
    private const AMMO_CATEGORY_MARKERS = [
        'AMMO', 'AMMUNITION', 'CENTERFIRE', 'RIMFIRE', 'SHOTSHELL', 'SHOT SHELL',
    ];

    public function __construct(
        private readonly AmmoAttributeExtractor $ammoAttributes = new AmmoAttributeExtractor,
    ) {}

    protected function feedSlug(): string
    {
        return 'chattanooga';
    }

    protected function defaultTransport(): string
    {
        return 'rest_api';
    }

    protected function defaultRemotePath(): string
    {
        return 'itemInventory.csv';
    }

    /** @return array<int, string> */
    protected function delimiterCandidates(): array
    {
        return [',', "\t", ';', '|'];
    }

    /**
     * Two-legged retrieval:
     *
     *  1. `GET /items/product-feed` (JSON) mints an export and returns
     *     its URL. Chattanooga throttles this endpoint to roughly one
     *     call per 20 minutes; a 429 / `too-many-requests` is surfaced
     *     as a clear, actionable failure.
     *  2. `GET {csvUrl}` streams the export to a temp file. This is a
     *     `.csv` endpoint that answers `406 Not Acceptable` to an
     *     `application/json` Accept header, so this leg sends a wildcard
     *     Accept (never the shared JSON {@see client()}), follows
     *     redirects and uses a generous timeout.
     */
    public function downloadFeed(Distributor $distributor): string
    {
        $settings = $this->settingsFor($distributor);

        $csvUrl = $this->resolveProductFeedUrl($settings);

        $localPath = tempnam(sys_get_temp_dir(), $this->feedSlug().'_feed_');
        if ($localPath === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the Chattanooga feed.');
        }

        try {
            $downloadResponse = Http::withHeaders([
                'Accept' => '*/*',
                'Authorization' => $this->authorizationHeader($settings),
            ])->withOptions([
                'sink' => $localPath,
                'timeout' => (int) ($settings['download_timeout'] ?? 300),
                'allow_redirects' => true,
            ])->get($csvUrl);

            if (! $downloadResponse->successful()) {
                throw new RuntimeException(
                    'Failed to download Chattanooga CSV: HTTP '.$downloadResponse->status(),
                );
            }
        } catch (\Throwable $e) {
            @unlink($localPath);
            throw $e;
        }

        if ((@filesize($localPath) ?: 0) === 0) {
            @unlink($localPath);
            throw new RuntimeException('Downloaded Chattanooga feed is empty.');
        }

        return $localPath;
    }

    public function testConnection(Distributor $distributor): bool
    {
        $settings = $this->settingsFor($distributor);

        try {
            return $this->client($settings)
                ->get($this->endpoint($settings, self::PRODUCT_FEED_ENDPOINT))
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ask the API to mint an inventory export and return its URL.
     *
     * @param  array<string, mixed>  $settings
     */
    private function resolveProductFeedUrl(array $settings): string
    {
        $response = $this->client($settings)
            ->get($this->endpoint($settings, self::PRODUCT_FEED_ENDPOINT));

        $this->guardAgainstRateLimit($response);

        $response->throw();

        $url = data_get($response->json(), 'product_feed.url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('Chattanooga product-feed response did not carry a download URL.');
        }

        return $url;
    }

    /**
     * Chattanooga throttles the export-generation endpoint hard (~one
     * call per 20 minutes). Turn a 429 — or a body carrying a
     * `too-many-requests` / rate-limit marker — into a clear, actionable
     * message instead of a generic HTTP exception.
     */
    private function guardAgainstRateLimit(Response $response): void
    {
        $haystack = strtolower(implode(' ', [
            $response->body(),
            (string) data_get($response->json(), 'error', ''),
            (string) data_get($response->json(), 'message', ''),
        ]));

        $rateLimited = $response->status() === 429
            || str_contains($haystack, 'too-many-requests')
            || str_contains($haystack, 'too many requests')
            || str_contains($haystack, 'rate limit')
            || str_contains($haystack, 'rate-limit');

        if ($rateLimited) {
            throw new RuntimeException(
                'Chattanooga feed export rate-limited: please wait before retrying '
                .'(the product-feed generation endpoint allows roughly one request every 20 minutes).',
            );
        }
    }

    /**
     * A JSON pending request carrying Chattanooga's authorization header.
     * Used only for the `/items/product-feed` generation leg; the CSV
     * download builds its own request with a wildcard Accept header.
     *
     * @param  array<string, mixed>  $settings
     */
    private function client(array $settings): PendingRequest
    {
        // Deliberately no ->retry(): the generation endpoint is throttled
        // to ~one call per 20 minutes, so an automatic retry on a 429
        // just deepens the cooldown. A 429/5xx comes back as a Response
        // for guardAgainstRateLimit() to translate.
        return Http::timeout((int) ($settings['timeout'] ?? 60))
            ->withHeaders([
                'Authorization' => $this->authorizationHeader($settings),
            ])
            ->acceptJson();
    }

    /**
     * Chattanooga's authorization header value.
     *
     * The API expects the literal string `Basic {SID}:{md5(token)}` — it
     * is NOT standard HTTP Basic auth, so the credential pair must not be
     * base64-encoded and `withBasicAuth()` must not be used; either one
     * returns `401 Bad Authorization`.
     *
     * @param  array<string, mixed>  $settings
     */
    private function authorizationHeader(array $settings): string
    {
        $sid = trim((string) ($settings['sid'] ?? ''));
        $token = trim((string) ($settings['token'] ?? ''));

        if ($sid === '' || $token === '') {
            throw new RuntimeException('Chattanooga feed credentials (sid / token) are not configured.');
        }

        return 'Basic '.$sid.':'.md5($token);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function endpoint(array $settings, string $path): string
    {
        $base = rtrim((string) ($settings['base_uri'] ?? self::API_BASE_URL), '/');

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     */
    protected function mapRow(array $row, array $columns): ?FeedItemDTO
    {
        $sku = $this->pick($row, $columns, [
            'cssiitemnumber', 'cssiitemno', 'cssi', 'itemnumber', 'itemno', 'item', 'sku', 'stocknumber',
        ], 0);

        if ($sku === null) {
            return null;
        }

        $description = $this->pick($row, $columns, [
            'itemdescription', 'webitemname', 'webitemdescription',
            'description', 'longdescription', 'shortdescription',
        ], 4) ?? '';

        $category = $this->pick($row, $columns, [
            'category', 'categorydescription', 'categoryname', 'department', 'class', 'producttype',
        ]);

        if (! $this->rowIsChattanoogaAmmo($category, $description)) {
            return null;
        }

        $manufacturer = $this->pick($row, $columns, [
            'manufacturer', 'mfg', 'mfgname', 'brand', 'manufacturername',
        ]);

        return FeedItemDTO::fromArray([
            'distributor_sku' => $sku,
            'raw_upc' => $this->cleanUpc($this->pick($row, $columns, [
                'upccode', 'upc', 'upcnumber', 'upcean', 'gtin',
            ], 1)),
            'raw_mfr_part_number' => $this->pick($row, $columns, [
                'manufactureritemnumber', 'manufacturerpartnumber', 'mfgitemnumber', 'mfgpartno',
                'mpn', 'model', 'modelnumber', 'partnumber',
            ], 2),
            'raw_description' => $description,
            'wholesale_price' => $this->toFloat($this->pick($row, $columns, [
                'price', 'dealerprice', 'cost', 'dealercost', 'wholesaleprice', 'yourprice',
            ])),
            'map_price' => $this->toNullableFloat($this->pick($row, $columns, [
                'mapprice', 'map', 'minimumadvertisedprice',
            ])),
            'msrp_price' => $this->toNullableFloat($this->pick($row, $columns, [
                'msrp', 'retail', 'retailprice', 'srp',
            ])),
            'quantity_available' => $this->toInt($this->pick($row, $columns, [
                'qtyonhand', 'quantityonhand', 'qtyavailable', 'quantityavailable', 'quantity', 'qty', 'onhand', 'available',
            ])),
            'raw_payload' => [
                'manufacturer' => $manufacturer,
                'category' => $category,
                'cells' => $row,
            ],
        ]);
    }

    /**
     * Chattanooga's ammunition filter:
     *
     *  1. an explicit ammunition category is kept outright;
     *  2. an explicit non-ammunition category (firearms, optics, cases,
     *     accessories) is dropped outright;
     *  3. a blank or ambiguous category is kept only when a caliber can
     *     be recovered from the description.
     */
    private function rowIsChattanoogaAmmo(?string $category, string $description): bool
    {
        $marker = strtoupper(trim((string) $category));

        if ($marker !== '') {
            foreach (self::AMMO_CATEGORY_MARKERS as $needle) {
                if (str_contains($marker, $needle)) {
                    return true;
                }
            }

            $verdict = $this->categoryIsAmmo($category);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        return $this->ammoAttributes->extractCaliber($description) !== null;
    }
}
