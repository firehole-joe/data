<?php

use App\Http\Controllers\Api\PublicSupplyReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
| Public supply-report syndication feed — token-gated, not a user
| session. Consumed by firehole.com/arms/2026-supply/ (WordPress /
| ThemeCo X-Pro Cornerstone Looper / WP Shortcodes) and research tools.
*/
Route::middleware('supply_report.api_key')->prefix('v1')->group(function () {
    Route::get('/supply-summary', [PublicSupplyReportController::class, 'summary'])
        ->name('api.supply-summary');

    Route::get('/brand-provenance', [PublicSupplyReportController::class, 'brandProvenance'])
        ->name('api.brand-provenance');
});
