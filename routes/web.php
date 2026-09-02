<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\DistributorAdminController;
use App\Http\Controllers\SupplyReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The 2026 Ammunition Supply Report dashboard for data.firehole.com.
|
*/

Route::get('/', [SupplyReportController::class, 'index'])->name('supply.index');
Route::get('/dashboard', [SupplyReportController::class, 'dashboard'])->name('supply.dashboard');
Route::get('/distributors', [SupplyReportController::class, 'distributors'])->name('supply.distributors');

/*
| Feed admin passphrase gate.
*/
Route::get('/admin/unlock', [AdminAuthController::class, 'showUnlockForm'])->name('admin.unlock');
Route::post('/admin/unlock', [AdminAuthController::class, 'unlock'])->name('admin.unlock.attempt');
Route::post('/admin/lock', [AdminAuthController::class, 'lock'])->name('admin.lock');

/*
| Distributor credential management (passphrase-protected).
*/
Route::middleware('feed.admin')->group(function () {
    Route::get('/distributors/{distributor:slug}/edit', [DistributorAdminController::class, 'edit'])
        ->name('distributors.edit');
    Route::put('/distributors/{distributor:slug}', [DistributorAdminController::class, 'update'])
        ->name('distributors.update');
    Route::post('/distributors/{distributor:slug}/test', [DistributorAdminController::class, 'testConnection'])
        ->name('distributors.test');
    Route::post('/distributors/{distributor:slug}/sync', [DistributorAdminController::class, 'manualSync'])
        ->name('distributors.sync');

    /*
    | Flagged-offering review resolution (from the dashboard drawer).
    */
    Route::patch('/offerings/{offering}/approve', [SupplyReportController::class, 'approveOffering'])
        ->name('supply.offerings.approve');
    Route::patch('/offerings/{offering}/ignore', [SupplyReportController::class, 'ignoreOffering'])
        ->name('supply.offerings.ignore');
    Route::post('/offerings/ignore-all', [SupplyReportController::class, 'ignoreAllOfferings'])
        ->name('supply.offerings.ignore_all');
});
