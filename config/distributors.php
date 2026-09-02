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

];
