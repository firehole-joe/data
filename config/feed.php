<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feed Admin Passphrase
    |--------------------------------------------------------------------------
    |
    | Lightweight shared passphrase that gates the distributor credential
    | management UI. Set FEED_ADMIN_PASSPHRASE in the environment; the
    | fallback below only applies when it is unset.
    |
    */

    'admin_passphrase' => env('FEED_ADMIN_PASSPHRASE', 'firehole2026'),

    /*
    |--------------------------------------------------------------------------
    | Sync Frequency Options
    |--------------------------------------------------------------------------
    |
    | Selectable cadences shown on the distributor edit form.
    |
    */

    'sync_frequencies' => [
        'hourly',
        'every_2_hours',
        'every_6_hours',
        'twice_daily',
        'daily',
        'manual',
    ],

];
