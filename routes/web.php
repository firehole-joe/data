<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DistributorAdminController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\SupplyReportController;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The 2026 Ammunition Supply Report for data.firehole.com — an internal
| operations tool. Crawlers are refused here (see robots.txt below and
| the SetRobotsHeaders middleware).
|
*/

Route::get('/', [LandingController::class, 'index'])->name('home');

// Served both as a static file (public/robots.txt) and here, so the
// disallow-all is guaranteed regardless of how the request is routed.
Route::get('/robots.txt', function () {
    return Response::make(
        "User-agent: *\nDisallow: /\n",
        200,
        ['Content-Type' => 'text/plain; charset=UTF-8'],
    );
})->name('robots');

/*
| Market data — viewable without an account.
*/
Route::get('/report', [SupplyReportController::class, 'index'])->name('supply.index');
Route::get('/dashboard', [SupplyReportController::class, 'dashboard'])->name('supply.dashboard');

/*
| Distributors & Feed Health — feed administrators only.
*/
Route::middleware('admin')->group(function () {
    Route::get('/distributors', [SupplyReportController::class, 'distributors'])->name('supply.distributors');
});

/*
| Authentication.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/password/reset', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/password/email', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/password/reset/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/password/reset', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
| Feed admin passphrase gate (skipped entirely for admin users).
*/
Route::get('/admin/unlock', [AdminAuthController::class, 'showUnlockForm'])->name('admin.unlock');
Route::post('/admin/unlock', [AdminAuthController::class, 'unlock'])->name('admin.unlock.attempt');
Route::post('/admin/lock', [AdminAuthController::class, 'lock'])->name('admin.lock');

/*
| Feed admin actions — an admin user passes straight through; everyone
| else must clear the passphrase modal first.
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
