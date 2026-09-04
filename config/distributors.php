<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-Distributor Connection Defaults
    |--------------------------------------------------------------------------
    |
    | Seed-time connection settings for distributor feeds. DistributorSeeder
    | copies the matching block into a distributor's encrypted
    | `connection_settings` bag; operators can still override any field
    | afterwards through the credential admin UI.
    |
    | Secrets (username / password) are read from the environment only and
    | have no committed fallback — set them in `.env`.
    |
    */

    'zanders' => [
        'transport' => 'ftp',
        'host' => env('ZANDERS_FTP_HOST', 'ftp2.gzanders.com'),
        'port' => (int) env('ZANDERS_FTP_PORT', 21),
        'username' => env('ZANDERS_FTP_USER'),
        'password' => env('ZANDERS_FTP_PASSWORD'),
        // The vendor drops the file in /Inventory; the driver falls back to
        // the repository root if that directory is unavailable.
        'remote_path' => env('ZANDERS_FTP_PATH', 'Inventory/zandersinv.csv'),
        'passive' => true,
        'ssl' => false,
    ],

    'chattanooga' => [
        'transport' => 'rest_api',
        // REST v6 base. `GET {base_uri}/items/product-feed` returns a JSON
        // envelope whose `product_feed.url` points at a freshly generated
        // itemInventory CSV export that the driver then stream-downloads.
        'base_uri' => env('CHATTANOOGA_API_URL', 'https://api.chattanoogashooting.com/rest/v6/'),
        // Basic auth is base64(SID . ':' . md5(TOKEN)); both halves come
        // from the environment and have no committed fallback.
        'sid' => env('CHATTANOOGA_SID'),
        'token' => env('CHATTANOOGA_TOKEN'),
    ],

    'davidsons' => [
        'transport' => 'sftp',
        'host' => env('DAVIDSONS_SFTP_HOST', 'ftp.davidsons.com'),
        'port' => (int) env('DAVIDSONS_SFTP_PORT', 22),
        'username' => env('DAVIDSONS_SFTP_USERNAME'),
        'password' => env('DAVIDSONS_SFTP_PASSWORD'),
        // Current-generation files only — the driver merges these two by
        // ItemNo; the legacy Itemspec.csv / Qty.csv are retired and are
        // never requested.
        'itemspec_path' => 'V2_Itemspec.csv',
        'qty_path' => 'V2_Qty.csv',
    ],

];
