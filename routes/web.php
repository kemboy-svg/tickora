<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Public\EventController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\OrganizerController;
use Illuminate\Support\Facades\Route;

// --- Public --------------------------------------------------------------

Route::get('/', HomeController::class)->name('home');
Route::get('events', [EventController::class, 'index'])->name('events.index');
Route::get('events/{event:slug}', [EventController::class, 'show'])->name('events.show');
Route::get('organizers/{organizer:slug}', [OrganizerController::class, 'show'])->name('organizers.show');

// --- Signed in -----------------------------------------------------------

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('checkout.store');
    Route::get('checkout/{checkout}', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('checkout/{checkout}/promo', [CheckoutController::class, 'promo'])
        ->middleware('throttle:20,1')
        ->name('checkout.promo');
    Route::post('checkout/{checkout}/confirm', [CheckoutController::class, 'confirm'])->name('checkout.confirm');
    Route::delete('checkout/{checkout}', [CheckoutController::class, 'destroy'])->name('checkout.destroy');
});

require __DIR__.'/organizer.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
