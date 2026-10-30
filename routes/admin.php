<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventReviewController;
use App\Http\Controllers\Admin\OrganizerController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Platform administration. Routes use the "two-factor" middleware (enforced
| in production, see tickora.require_two_factor); each controller also checks
| the platform permission it needs.
*/
Route::middleware(['auth', 'verified', 'two-factor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('events', [EventReviewController::class, 'index'])->name('events.index');
    Route::post('events/{event}/approve', [EventReviewController::class, 'approve'])->name('events.approve');
    Route::post('events/{event}/reject', [EventReviewController::class, 'reject'])->name('events.reject');
    Route::patch('events/{event}/payment-provider', [EventReviewController::class, 'paymentProvider'])->name('events.payment-provider');

    Route::get('payments', [PaymentSettingsController::class, 'index'])->name('payments.index');
    Route::put('payments/default', [PaymentSettingsController::class, 'setDefault'])->name('payments.default');
    Route::put('payments/{provider}', [PaymentSettingsController::class, 'update'])
        ->where('provider', 'stripe|paystack')
        ->name('payments.update');
    Route::post('payments/{provider}/test', [PaymentSettingsController::class, 'test'])
        ->where('provider', 'stripe|paystack')
        ->middleware('throttle:10,1')
        ->name('payments.test');

    Route::get('organizers', [OrganizerController::class, 'index'])->name('organizers.index');
    Route::patch('organizers/{organizer}/status', [OrganizerController::class, 'update'])->name('organizers.status');
});
