<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\UsageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:api-auth', 'merchant.auth'])->group(function (): void {
    Route::post('usage', [UsageController::class, 'store'])->middleware('throttle:usage')->name('usage.store');
    Route::middleware('throttle:merchant-api')->group(function (): void {
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan')->name('plans.update');
        Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
        Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])->whereNumber('subscription')->name('subscriptions.show');
        Route::put('subscriptions/{subscription}/plan', [SubscriptionPlanController::class, 'update'])->whereNumber('subscription')->name('subscriptions.plan.update');
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
        Route::get('merchants/{merchant}/dashboard', [DashboardController::class, 'show'])->whereNumber('merchant')->name('dashboard.show');
    });
});
