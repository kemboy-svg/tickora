<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

/*
 * The mobile app's API. Token authentication (Sanctum); every response is
 * JSON. The app checks /api/v1/ping when a tester enters the server address.
 */
Route::prefix('v1')->name('api.')->middleware('throttle:api')->group(function () {
    Route::get('ping', fn () => response()->json([
        'app' => 'tickora',
        'api_version' => 1,
        'name' => config('app.name'),
        'sandbox_payments' => ! app()->isProduction(),
    ]))->name('ping');

    Route::get('events', [EventController::class, 'index'])->name('events.index');
    Route::get('events/{event:slug}', [EventController::class, 'show'])->name('events.show');
    Route::get('categories', [EventController::class, 'categories'])->name('categories');

    Route::middleware('throttle:api-auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('login');
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('me/verification-notification', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::middleware('verified')->group(function () {
            Route::post('checkouts', [CheckoutController::class, 'store'])->middleware('throttle:20,1')->name('checkouts.store');
            Route::get('checkouts/{checkout}', [CheckoutController::class, 'show'])->name('checkouts.show');
            Route::post('checkouts/{checkout}/promo', [CheckoutController::class, 'promo'])->middleware('throttle:20,1')->name('checkouts.promo');
            Route::post('checkouts/{checkout}/pay', [CheckoutController::class, 'pay'])->middleware('throttle:20,1')->name('checkouts.pay');
            Route::delete('checkouts/{checkout}', [CheckoutController::class, 'destroy'])->name('checkouts.destroy');

            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        });
    });
});
