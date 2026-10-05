<?php

use App\Http\Controllers\MerchantDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MerchantDashboardController::class, 'index'])->name('dashboard.index');
Route::post('/dashboard/session', [MerchantDashboardController::class, 'store'])
    ->middleware('throttle:10,1')->name('dashboard.session.store');
Route::delete('/dashboard/session', [MerchantDashboardController::class, 'destroy'])->name('dashboard.session.destroy');
