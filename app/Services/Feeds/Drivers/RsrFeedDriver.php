<?php

namespace App\Services\Feeds\Drivers;

use App\Models\Distributor;
use App\Services\Feeds\Contracts\FeedDriverInterface;
use App\Services\Feeds\DTOs\FeedItemDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;

/**
 * Feed driver for RSR Group.
 *
 * RSR publishes a semicolon-delimited fixed-column inventory file
 * (historically `rsrinventory-new.txt`). This driver knows how to pull
 * that file over SFTP, FTP or HTTP and how to turn each line into a
 * {@see FeedItemDTO}, discarding anything that is not ammunition.
 */
class RsrFeedDriver implements FeedDriverInterface
{
    /** Number of DTOs to buffer before the generator is nudged. */
    private const READ_CHUNK_BYTES = 1024 * 1024;

    /** Delimiters we know RSR (and RSR-style) feeds use, most common first. */
    private const CANDIDATE_DELIMITERS = [';', "\t", '|', ','];

    /**
     * Zero-based column positions in the RSR inventory file.
     *
     * Only the columns this system cares about are listed; the file has
     * ~77 columns in total and rows shorter than a given index are
     * tolerated.
     */
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
    private const DEFAULT_AMMUNITION_DEPARTMENTS = ['18'];

    public function downloadFeed(Distributor $distributor): string
    {
        $settings = (array) ($distributor->connection_settings ?? []);
        $transport = strtolower((string) ($distributor->transport_type ?: ($settings['transport'] ?? 'sftp')));

        $localPath = tempnam(sys_get_temp_dir(), 'rsr_feed_');
        if ($localPath === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the RSR feed download.');
        }

        Log::channel('daily')->info('rsr.download.start', [
            'distributor' => $distributor->slug,
            'transport' => $transport,
        ]);

        try {
            match ($transport) {
                'sftp' => $this->downloadViaSftp($settings, $localPath),
                'ftp' => $this->downloadViaFtp($settings, $localPath),
                'http', 'https', 'http_csv', 'rest_api' => $this->downloadViaHttp($settings, $localPath),
                default => throw new RuntimeException("Unsupported RSR transport type [{$transport}]."),
            };
        } catch (\Throwable $e) {
            @unlink($localPath);
            Log::channel('daily')->error('rsr.download.failed', [
                'distributor' => $distributor->slug,
                'transport' => $transport,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $bytes = @filesize($localPath) ?: 0;
        Log::channel('daily')->info('rsr.download.complete', [
            'distributor' => $distributor->slug,
            'bytes' => $bytes,
        ]);

        if ($bytes === 0) {
            throw new RuntimeException("Downloaded RSR feed for [{$distributor->slug}] is empty.");
        }

        return $localPath;
    }

    /**
     * @return \Generator<int, FeedItemDTO>
     */
    public function parseFeed(string $filePath): \Generator
    {
        if (! is_readable($filePath)) {
            throw new RuntimeException("RSR feed file [{$filePath}] is not readable.");
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open RSR feed file [{$filePath}].");
        }

        try {
            $delimiter = $this->detectDelimiter($filePath);
            $ammoDepartments = self::DEFAULT_AMMUNITION_DEPARTMENTS;
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    continue;
                }

                $fields = explode($delimiter, $line);

                if ($lineNumber === 1 && $this->looksLikeHeader($fields)) {
                    continue;
                }

                $fields = array_map('trim', $fields);

                if (! $this->isAmmunition($fields, $ammoDepartments)) {
                    continue;
                }

                $sku = $fields[self::COL_STOCK_NUMBER] ?? '';
                if ($sku === '') {
                    continue;
                }

                yield FeedItemDTO::fromArray([
                    'distributor_sku' => $sku,
                    'raw_upc' => $this->cleanUpc($fields[self::COL_UPC] ?? null),
                    'raw_mfr_part_number' => $fields[self::COL_MFR_PART_NUMBER] ?? null,
                    'raw_description' => $this->bestDescription($fields),
                    'wholesale_price' => $this->parsePrice($fields[self::COL_WHOLESALE_PRICE] ?? null),
                    'map_price' => $this->parseNullablePrice($fields[self::COL_MAP_PRICE] ?? null),
                    'msrp_price' => $this->parseNullablePrice($fields[self::COL_MSRP_PRICE] ?? null),
                    'quantity_available' => $this->parseQuantity($fields[self::COL_QUANTITY] ?? null),
                    'raw_payload' => [
                        'line' => $lineNumber,
                        'department' => $fields[self::COL_DEPARTMENT] ?? null,
                        'manufacturer' => $fields[self::COL_MANUFACTURER] ?? null,
                        'fields' => $fields,
                    ],
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    public function testConnection(Distributor $distributor): bool
    {
        $settings = (array) ($distributor->connection_settings ?? []);
        $transport = strtolower((string) ($distributor->transport_type ?: ($settings['transport'] ?? 'sftp')));

        try {
            return match ($transport) {
                'sftp' => $this->sftpFilesystem($settings)->directoryExists($this->directoryOf($this->remotePath($settings))),
                'ftp' => $this->ftpFilesystem($settings)->directoryExists($this->directoryOf($this->remotePath($settings))),
                'http', 'https', 'http_csv', 'rest_api' => $this->httpRequest($settings)
                    ->head($this->httpUrl($settings))->successful(),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('rsr.test_connection.failed', [
                'distributor' => $distributor->slug,
                'transport' => $transport,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
        $response = $this->httpRequest($settings)
            ->withOptions(['sink' => $localPath])
            ->get($this->httpUrl($settings));

        $response->throw();
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
    private function httpRequest(array $settings): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout((int) ($settings['timeout'] ?? 60))
            ->retry((int) ($settings['retries'] ?? 2), 250);

        if (! empty($settings['username'])) {
            $request = $request->withBasicAuth(
                (string) $settings['username'],
                (string) ($settings['password'] ?? ''),
            );
        }

        if (! empty($settings['api_key'])) {
            $request = $request->withToken((string) $settings['api_key']);
        }

        return $request;
    }

    /* -------------------------------------------------------------------- */
    /*  Parsing helpers */
    /* -------------------------------------------------------------------- */

    private function detectDelimiter(string $filePath): string
    {
        $sample = (string) file_get_contents($filePath, false, null, 0, self::READ_CHUNK_BYTES);
        $firstLine = strtok($sample, "\n") ?: $sample;

        $best = self::CANDIDATE_DELIMITERS[0];
        $bestCount = -1;

        foreach (self::CANDIDATE_DELIMITERS as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function looksLikeHeader(array $fields): bool
    {
        $joined = strtolower(implode(' ', array_slice($fields, 0, 4)));

        return str_contains($joined, 'stock')
            || str_contains($joined, 'upc')
            || str_contains($joined, 'description');
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $ammoDepartments
     */
    private function isAmmunition(array $fields, array $ammoDepartments): bool
    {
        $department = isset($fields[self::COL_DEPARTMENT])
            ? trim((string) $fields[self::COL_DEPARTMENT])
            : '';

        // When the feed does not carry department information we cannot
        // filter, so we let the row through and rely on later matching.
        if ($department === '') {
            return true;
        }

        return in_array($department, $ammoDepartments, true);
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function bestDescription(array $fields): string
    {
        $expanded = trim((string) ($fields[self::COL_EXPANDED_DESCRIPTION] ?? ''));
        $short = trim((string) ($fields[self::COL_DESCRIPTION] ?? ''));

        return $expanded !== '' ? $expanded : $short;
    }

    /**
     * Strip everything that is not a digit and left-pad short codes to
     * 12 digits (UPC-A). 13/14 digit codes (EAN-13 / GTIN-14) are left
     * untouched.
     */
    private function cleanUpc(?string $value): ?string
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

    private function parsePrice(?string $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }

    private function parseNullablePrice(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $value);

        if ($clean === '' || ! is_numeric($clean) || (float) $clean <= 0.0) {
            return null;
        }

        return (float) $clean;
    }

    private function parseQuantity(?string $value): int
    {
        return max((int) preg_replace('/[^0-9\-]/', '', (string) $value), 0);
    }

    /* -------------------------------------------------------------------- */
    /*  Path helpers */
    /* -------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $settings
     */
    private function remotePath(array $settings): string
    {
        $path = (string) ($settings['remote_path'] ?? $settings['path'] ?? 'rsrinventory-new.txt');

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
        $url = (string) ($settings['url'] ?? $settings['feed_url'] ?? '');

        if ($url === '' && ! empty($settings['base_uri'])) {
            $url = rtrim((string) $settings['base_uri'], '/').'/'.$this->remotePath($settings);
        }

        if ($url === '') {
            throw new RuntimeException('No HTTP feed URL configured for RSR distributor.');
        }

        return $url;
    }

    private function directoryOf(string $path): string
    {
        $directory = trim(dirname($path), '.');

        return $directory === '' ? '.' : $directory;
    }
}
