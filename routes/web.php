<?php

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
Route::get('/distributors', [SupplyReportController::class, 'distributors'])->name('supply.distributors');
