<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Payments\SandboxPaymentController;
use App\Http\Controllers\Payments\WebhookController;
use App\Http\Controllers\Public\EventController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\OrganizerController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

// --- Public --------------------------------------------------------------

Route::get('/', HomeController::class)->name('home');
Route::get('events', [EventController::class, 'index'])->name('events.index');
Route::get('events/{event:slug}', [EventController::class, 'show'])->name('events.show');
Route::get('organizers/{organizer:slug}', [OrganizerController::class, 'show'])->name('organizers.show');

// --- Payment provider callbacks (signature-authenticated, no CSRF) -------

Route::post('webhooks/payments/{provider}', WebhookController::class)
    ->where('provider', '[a-z0-9_-]{1,40}')
    ->middleware('throttle:240,1')
    ->name('webhooks.payments');

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
    Route::post('checkout/{checkout}/pay', [CheckoutController::class, 'pay'])
        ->middleware('throttle:20,1')
        ->name('checkout.pay');
    Route::delete('checkout/{checkout}', [CheckoutController::class, 'destroy'])->name('checkout.destroy');

    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');

    // The sandbox provider's payment screen. Never registered in production.
    if (! app()->isProduction()) {
        Route::get('sandbox/payments/{paymentId}', [SandboxPaymentController::class, 'show'])
            ->where('paymentId', 'fake_pay_[a-f0-9]{24}')
            ->name('sandbox.payments.show');
        Route::post('sandbox/payments/{paymentId}', [SandboxPaymentController::class, 'update'])
            ->where('paymentId', 'fake_pay_[a-f0-9]{24}')
            ->middleware('throttle:30,1')
            ->name('sandbox.payments.update');
    }
});

require __DIR__.'/organizer.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
