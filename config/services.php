<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Supply Report API
    |--------------------------------------------------------------------------
    |
    | Shared key gating the public /api/v1/supply-summary feed consumed by
    | firehole.com/arms/2026-supply/ (WordPress / ThemeCo X-Pro Cornerstone
    | Looper) and research assistants. Accepted as ?api_key=... or an
    | `Authorization: Bearer ...` header — see
    | App\Http\Middleware\EnsureValidSupplyReportApiKey.
    |
    */

    'reports' => [
        'api_key' => env('SUPPLY_REPORT_API_KEY', 'firehole-supply-report-2026'),
    ],

];
