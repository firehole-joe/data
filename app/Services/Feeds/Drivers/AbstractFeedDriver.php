<?php

namespace App\Services\Feeds\Drivers;

use App\Models\Distributor;
use App\Services\Feeds\Contracts\FeedDriverInterface;
use App\Services\Feeds\DTOs\FeedItemDTO;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Shared plumbing for every distributor feed driver.
 *
 * Concrete drivers only have to declare their slug, default remote path,
 * default transport, and how to translate one delimited row (and,
 * optionally, one JSON object) into a {@see FeedItemDTO}. Everything else
 * — SFTP/FTP/HTTP retrieval, delimiter sniffing, header indexing, numeric
 * coercion, UPC cleaning and ammunition classification — lives here.
 */
abstract class AbstractFeedDriver implements FeedDriverInterface
{
    private const READ_SAMPLE_BYTES = 65536;

    /* -------------------------------------------------------------------- */
    /*  Required per-driver hooks */
    /* -------------------------------------------------------------------- */

    /** Short identifier used in log context, e.g. "chattanooga". */
    abstract protected function feedSlug(): string;

    /** Remote filename used when connection_settings does not name one. */
    abstract protected function defaultRemotePath(): string;

    /**
     * Translate one delimited row into a DTO, or return null to drop it.
     *
     * @param  array<int, string>  $row  Positional cell values.
     * @param  array<string, int>  $columns  Normalised header name => column index
     *                                       (empty when the feed carries no header).
     */
    abstract protected function mapRow(array $row, array $columns): ?FeedItemDTO;

    /* -------------------------------------------------------------------- */
    /*  Optional per-driver overrides */
    /* -------------------------------------------------------------------- */

    protected function defaultTransport(): string
    {
        return 'http_csv';
    }

    protected function expectsHeaderRow(): bool
    {
        return true;
    }

    /** @return array<int, string> */
    protected function delimiterCandidates(): array
    {
        return [',', ';', "\t", '|'];
    }

    /**
     * Split one raw line into trimmed cells. CSV-aware by default; a
     * fixed-field feed (e.g. RSR) overrides this with a plain explode().
     *
     * @return array<int, string>
     */
    protected function splitLine(string $line, string $delimiter): array
    {
        return array_map(
            static fn ($cell) => trim((string) $cell),
            str_getcsv($line, $delimiter, '"', '\\'),
        );
    }

    /**
     * Translate one JSON product object into a DTO, or null to drop it.
     * Only reached when the downloaded payload is JSON.
     *
     * @param  array<string, mixed>  $item
     */
    protected function mapJsonRow(array $item): ?FeedItemDTO
    {
        return null;
    }

    /** Keys under which a JSON payload may nest its product list. */
    protected function jsonListKeys(): array
    {
        return ['data', 'products', 'items', 'results', 'rows', 'inventory', 'catalog'];
    }

    /* -------------------------------------------------------------------- */
    /*  FeedDriverInterface */
    /* -------------------------------------------------------------------- */

    public function downloadFeed(Distributor $distributor): string
    {
        $settings = (array) ($distributor->connection_settings ?? []);
        $transport = $this->transportFor($distributor, $settings);

        $localPath = tempnam(sys_get_temp_dir(), $this->feedSlug().'_feed_');
        if ($localPath === false) {
            throw new RuntimeException("Unable to allocate a temporary file for the {$this->feedSlug()} feed.");
        }

        $this->log()->info('feed.download.start', [
            'feed' => $this->feedSlug(),
            'transport' => $transport,
        ]);

        try {
            match ($transport) {
                'sftp' => $this->downloadViaSftp($settings, $localPath),
                'ftp', 'ftps' => $this->downloadViaFtp($settings, $localPath),
                'http', 'https', 'http_csv', 'rest_api', 'api' => $this->downloadViaHttp($settings, $localPath),
                default => throw new RuntimeException("Unsupported transport [{$transport}] for the {$this->feedSlug()} feed."),
            };
        } catch (\Throwable $e) {
            @unlink($localPath);
            $this->log()->error('feed.download.failed', [
                'feed' => $this->feedSlug(),
                'transport' => $transport,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $bytes = @filesize($localPath) ?: 0;
        $this->log()->info('feed.download.complete', [
            'feed' => $this->feedSlug(),
            'bytes' => $bytes,
        ]);

        if ($bytes === 0) {
            @unlink($localPath);
            throw new RuntimeException("Downloaded {$this->feedSlug()} feed is empty.");
        }

        return $localPath;
    }

    /**
     * @return \Generator<int, FeedItemDTO>
     */
    public function parseFeed(string $filePath): \Generator
    {
        if (! is_readable($filePath)) {
            throw new RuntimeException("Feed file [{$filePath}] is not readable.");
        }

        $lead = $this->leadingByte($filePath);

        if ($lead === '{' || $lead === '[') {
            yield from $this->parseJson($filePath);

            return;
        }

        yield from $this->parseDelimited($filePath);
    }

    public function testConnection(Distributor $distributor): bool
    {
        $settings = (array) ($distributor->connection_settings ?? []);
        $transport = $this->transportFor($distributor, $settings);

        try {
            return match ($transport) {
                'sftp' => $this->sftpFilesystem($settings)->directoryExists($this->directoryOf($this->remotePath($settings))),
                'ftp', 'ftps' => $this->ftpFilesystem($settings)->directoryExists($this->directoryOf($this->remotePath($settings))),
                'http', 'https', 'http_csv', 'rest_api', 'api' => $this->httpRequest($settings)->head($this->httpUrl($settings))->successful(),
                default => false,
            };
        } catch (\Throwable $e) {
            $this->log()->warning('feed.test_connection.failed', [
                'feed' => $this->feedSlug(),
                'transport' => $transport,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /* -------------------------------------------------------------------- */
    /*  Parsing */
    /* -------------------------------------------------------------------- */

    /**
     * @return \Generator<int, FeedItemDTO>
     */
    private function parseDelimited(string $filePath): \Generator
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open feed file [{$filePath}].");
        }

        try {
            $delimiter = $this->detectDelimiter($filePath);
            $columns = [];
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = rtrim($line, "\r\n");

                if ($lineNumber === 1) {
                    $line = ltrim($line, "\u{FEFF}");
                }

                if ($line === '') {
                    continue;
                }

                $cells = $this->splitLine($line, $delimiter);

                if ($lineNumber === 1 && ($this->expectsHeaderRow() || $this->looksLikeHeaderRow($cells))) {
                    $columns = $this->indexHeader($cells);

                    continue;
                }

                $dto = $this->mapRow($cells, $columns);

                if ($dto !== null) {
                    yield $dto;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return \Generator<int, FeedItemDTO>
     */
    private function parseJson(string $filePath): \Generator
    {
        $decoded = json_decode((string) file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);

        foreach ($this->extractJsonList($decoded) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $dto = $this->mapJsonRow($item);

            if ($dto !== null) {
                yield $dto;
            }
        }
    }

    /**
     * @return iterable<int, mixed>
     */
    private function extractJsonList(mixed $decoded): iterable
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        foreach ($this->jsonListKeys() as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }

        // One level of nesting, e.g. { "response": { "products": [ ... ] } }.
        foreach ($decoded as $value) {
            if (is_array($value) && ! array_is_list($value)) {
                foreach ($this->jsonListKeys() as $key) {
                    if (isset($value[$key]) && is_array($value[$key])) {
                        return $value[$key];
                    }
                }
            }
        }

        return [];
    }

    private function leadingByte(string $filePath): string
    {
        $sample = (string) file_get_contents($filePath, false, null, 0, 512);
        $sample = ltrim($sample, " \t\r\n\0\x0B\u{FEFF}");

        return $sample === '' ? '' : $sample[0];
    }

    private function detectDelimiter(string $filePath): string
    {
        $sample = (string) file_get_contents($filePath, false, null, 0, self::READ_SAMPLE_BYTES);
        $firstLine = strtok($sample, "\n");
        $firstLine = $firstLine === false ? $sample : $firstLine;

        $candidates = $this->delimiterCandidates();
        $best = $candidates[0];
        $bestCount = -1;

        foreach ($candidates as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, string>  $cells
     * @return array<string, int>
     */
    private function indexHeader(array $cells): array
    {
        $columns = [];

        foreach ($cells as $index => $name) {
            $key = $this->normalizeKey($name);
            if ($key !== '' && ! isset($columns[$key])) {
                $columns[$key] = $index;
            }
        }

        return $columns;
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function looksLikeHeaderRow(array $cells): bool
    {
        $joined = strtolower(implode(' ', array_slice($cells, 0, 8)));

        foreach (['upc', 'description', 'item', 'sku', 'price', 'qty', 'quantity', 'cost'] as $token) {
            if (str_contains($joined, $token)) {
                return true;
            }
        }

        return false;
    }

    /* -------------------------------------------------------------------- */
    /*  Value helpers (available to concrete drivers) */
    /* -------------------------------------------------------------------- */

    /**
     * Fetch a cell by any header alias, falling back to a positional index.
     *
     * @param  array<int, string>  $row
     * @param  array<string, int>  $columns
     * @param  array<int, string>  $aliases
     */
    protected function pick(array $row, array $columns, array $aliases, ?int $fallbackIndex = null): ?string
    {
        foreach ($aliases as $alias) {
            $key = $this->normalizeKey($alias);
            if (isset($columns[$key]) && array_key_exists($columns[$key], $row)) {
                $value = trim((string) $row[$columns[$key]]);

                return $value === '' ? null : $value;
            }
        }

        if ($fallbackIndex !== null && array_key_exists($fallbackIndex, $row)) {
            $value = trim((string) $row[$fallbackIndex]);

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * Fetch a value from a JSON object by any of the given keys, matching
     * case- and separator-insensitively.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $keys
     */
    protected function jsonValue(array $item, array $keys): mixed
    {
        $normalised = [];
        foreach ($item as $key => $value) {
            $normalised[$this->normalizeKey((string) $key)] = $value;
        }

        foreach ($keys as $key) {
            $nk = $this->normalizeKey($key);
            if (array_key_exists($nk, $normalised) && $normalised[$nk] !== null && $normalised[$nk] !== '') {
                return $normalised[$nk];
            }
        }

        return null;
    }

    protected function normalizeKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($name))) ?? '';
    }

    /**
     * Digits-only UPC, left-padded to 12 (UPC-A). 13/14-digit codes are
     * kept as-is; anything longer is truncated to 14.
     */
    protected function cleanUpc(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '' || (int) $digits === 0) {
            return null;
        }

        if (strlen($digits) < 12) {
            $digits = str_pad($digits, 12, '0', STR_PAD_LEFT);
        }

        return substr($digits, 0, 14);
    }

    protected function toFloat(mixed $value): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value) ?? '';

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    protected function toNullableFloat(mixed $value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';

        if ($clean === '' || ! is_numeric($clean) || (float) $clean <= 0.0) {
            return null;
        }

        return (float) $clean;
    }

    protected function toInt(mixed $value): int
    {
        $clean = preg_replace('/[^0-9\-]/', '', (string) $value) ?? '';

        return max((int) $clean, 0);
    }

    /* -------------------------------------------------------------------- */
    /*  Ammunition classification */
    /* -------------------------------------------------------------------- */

    /**
     * Decide whether a row is loaded ammunition. An explicit category
     * wins; otherwise the description is inspected heuristically.
     */
    protected function rowIsAmmunition(?string $category, ?string $description): bool
    {
        $explicit = $this->categoryIsAmmo($category);

        if ($explicit !== null) {
            return $explicit;
        }

        return $this->looksLikeAmmo($category, $description);
    }

    /**
     * Classify an explicit category string.
     *
     * @return bool|null true / false when recognised, null when ambiguous.
     */
    protected function categoryIsAmmo(?string $category): ?bool
    {
        $category = strtolower(trim((string) $category));
        if ($category === '') {
            return null;
        }

        foreach (['ammunition', 'ammo', 'centerfire', 'rimfire', 'shotshell', 'shot shell', 'cartridge'] as $needle) {
            if (str_contains($category, $needle)) {
                return true;
            }
        }

        foreach ([
            'handgun', 'pistol', 'revolver', 'rifle', 'shotgun', 'long gun', 'firearm', 'optic', 'scope',
            'sight', 'magazine', 'holster', 'knife', 'light', 'laser', 'apparel', 'clothing', 'safe',
            'cleaning', 'reloading', 'accessor', 'grip', 'stock', 'barrel', 'case', 'bag', 'target',
            'sling', 'battery', 'part',
        ] as $needle) {
            if (str_contains($category, $needle)) {
                return false;
            }
        }

        return null;
    }

    /**
     * Heuristic ammunition detector for feeds without a usable category.
     */
    protected function looksLikeAmmo(?string ...$signals): bool
    {
        $haystack = strtolower(trim(implode(' ', array_filter(
            $signals,
            static fn ($signal) => $signal !== null && $signal !== '',
        ))));

        if ($haystack === '') {
            return false;
        }

        $hasAmmoWord = (bool) preg_match('/\b(ammunition|ammo|centerfire|rimfire|cartridges?)\b/', $haystack);
        $hasWeight = (bool) preg_match('/\b\d{1,4}\s?gr(?:ain)?s?\b/', $haystack);
        $hasBulletType = (bool) preg_match(
            '/\b(fmj|tmj|jhp|jsp|jrn|lrn|fmjbt|hpbt|bthp|otm|v-?max|z-?max|eld[- ]?\w*|sst|a-?max|hollow ?point|soft ?point|full metal jacket|total metal jacket|open tip|round nose|polymer tip|ballistic tip|frangible)\b/',
            $haystack,
        );
        $hasShotshell = (bool) preg_match('/\b(buck ?shot|bird ?shot|\d{1,2} ?(?:buck|shot)|rifled slug|\bslug\b|#[0-9]{1,2} ?shot)\b/', $haystack);

        if ($hasAmmoWord || $hasWeight || $hasBulletType || $hasShotshell) {
            return true;
        }

        if ($this->mentionsFirearm($haystack)) {
            return false;
        }

        $hasRounds = (bool) preg_match('/\b\d{1,4}\s?(?:rd|rds|rnd|rnds|round|rounds)\b/', $haystack);

        return $hasRounds && $this->mentionsCaliber($haystack);
    }

    private function mentionsFirearm(string $haystack): bool
    {
        return (bool) preg_match('/\b(pistol|revolver|rifle|carbine|shotgun|receiver|frame|handgun|firearm)\b/', $haystack)
            || (bool) preg_match('/\bgen\s?[0-9]\b/', $haystack)
            || (bool) preg_match('/\b(bolt|lever|pump)[ -]?action\b/', $haystack);
    }

    private function mentionsCaliber(string $haystack): bool
    {
        return (bool) preg_match(
            '/\b(9\s?mm|10\s?mm|45\s?acp|40\s?s&?w|380\s?(?:acp|auto)|357\s?(?:mag|sig)|38\s?spl|5\.56|7\.62|6\.5|\.?300\s?(?:blk|blackout|aac|win)|\.?308|\.?223|\.?22\s?(?:lr|wmr|mag)|12\s?ga|20\s?ga|16\s?ga|28\s?ga|410)\b/',
            $haystack,
        );
    }

    /* -------------------------------------------------------------------- */
    /*  Transports */
    /* -------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $settings
     */
    private function downloadViaSftp(array $settings, string $localPath): void
    {
        $this->streamToFile(
            $this->sftpFilesystem($settings)->readStream($this->remotePath($settings)),
            $localPath,
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function downloadViaFtp(array $settings, string $localPath): void
    {
        $this->streamToFile(
            $this->ftpFilesystem($settings)->readStream($this->remotePath($settings)),
            $localPath,
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function downloadViaHttp(array $settings, string $localPath): void
    {
        $this->httpRequest($settings)
            ->withOptions(['sink' => $localPath])
            ->get($this->httpUrl($settings))
            ->throw();
    }

    /**
     * @param  resource|false  $stream
     */
    private function streamToFile($stream, string $localPath): void
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('Remote feed stream could not be opened.');
        }

        $target = fopen($localPath, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new RuntimeException("Unable to write to local feed file [{$localPath}].");
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function sftpFilesystem(array $settings): Filesystem
    {
        $provider = new SftpConnectionProvider(
            host: (string) ($settings['host'] ?? ''),
            username: (string) ($settings['username'] ?? ''),
            password: isset($settings['password']) ? (string) $settings['password'] : null,
            privateKey: $settings['private_key'] ?? null,
            passphrase: $settings['passphrase'] ?? null,
            port: (int) ($settings['port'] ?? 22),
            timeout: (int) ($settings['timeout'] ?? 30),
        );

        return new Filesystem(new SftpAdapter($provider, $this->baseDirectory($settings)));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function ftpFilesystem(array $settings): Filesystem
    {
        return new Filesystem(new FtpAdapter(FtpConnectionOptions::fromArray([
            'host' => (string) ($settings['host'] ?? ''),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'port' => (int) ($settings['port'] ?? 21),
            'root' => $this->baseDirectory($settings),
            'ssl' => (bool) ($settings['ssl'] ?? false),
            'passive' => (bool) ($settings['passive'] ?? true),
            'timeout' => (int) ($settings['timeout'] ?? 30),
        ])));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function httpRequest(array $settings): PendingRequest
    {
        $request = Http::timeout((int) ($settings['timeout'] ?? 60))
            ->retry((int) ($settings['retries'] ?? 2), 250)
            ->acceptJson();

        if (! empty($settings['username'])) {
            $request = $request->withBasicAuth(
                (string) $settings['username'],
                (string) ($settings['password'] ?? ''),
            );
        }

        $token = $settings['api_key'] ?? $settings['token'] ?? $settings['bearer_token'] ?? null;
        if (! empty($token)) {
            $request = $request->withToken((string) $token);
        }

        if (! empty($settings['headers']) && is_array($settings['headers'])) {
            $request = $request->withHeaders($settings['headers']);
        }

        return $request;
    }

    /* -------------------------------------------------------------------- */
    /*  Path / config helpers */
    /* -------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $settings
     */
    private function transportFor(Distributor $distributor, array $settings): string
    {
        $transport = $distributor->transport_type
            ?: ($settings['transport'] ?? $this->defaultTransport());

        return strtolower((string) $transport);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function remotePath(array $settings): string
    {
        $path = (string) ($settings['remote_path'] ?? $settings['path'] ?? $this->defaultRemotePath());

        return ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function baseDirectory(array $settings): string
    {
        return (string) ($settings['base_directory'] ?? $settings['root'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function httpUrl(array $settings): string
    {
        $url = (string) ($settings['url'] ?? $settings['feed_url'] ?? $settings['endpoint'] ?? '');

        if ($url === '' && ! empty($settings['base_uri'])) {
            $url = rtrim((string) $settings['base_uri'], '/').'/'.$this->remotePath($settings);
        }

        if ($url === '') {
            throw new RuntimeException("No HTTP feed URL configured for the {$this->feedSlug()} feed.");
        }

        return $url;
    }

    private function directoryOf(string $path): string
    {
        $directory = trim(dirname($path), '.');

        return $directory === '' ? '.' : $directory;
    }

    private function log(): LoggerInterface
    {
        return Log::channel('daily');
    }
}
