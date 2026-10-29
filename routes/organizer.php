<?php

use App\Http\Controllers\Organizer\DashboardController;
use App\Http\Controllers\Organizer\EventController;
use App\Http\Controllers\Organizer\EventImageController;
use App\Http\Controllers\Organizer\OnboardingController;
use App\Http\Controllers\Organizer\PromoCodeController;
use App\Http\Controllers\Organizer\SwitchWorkspaceController;
use App\Http\Controllers\Organizer\TicketTypeController;
use App\Http\Middleware\EnsureOrganizerWorkspace;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
| {managedEvent} resolves only among events of organisers the signed-in user
| actively belongs to. Another organiser's event is a 404 before any policy
| runs; policies then decide what this member may do with it.
*/
Route::bind('managedEvent', function (string $value): Event {
    $user = Auth::user();
    abort_unless($user instanceof User, 404);

    return Event::query()->visibleTo($user)->where('public_id', $value)->firstOrFail();
});

Route::middleware(['auth', 'verified'])->prefix('organizer')->name('organizer.')->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'create'])->name('onboarding');
    Route::post('onboarding', [OnboardingController::class, 'store'])
        ->middleware('throttle:10,60')
        ->name('onboarding.store');

    Route::middleware(EnsureOrganizerWorkspace::class)->group(function () {
        Route::redirect('/', '/organizer/dashboard')->name('home');
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::post('switch', SwitchWorkspaceController::class)->name('switch');

        Route::get('events', [EventController::class, 'index'])->name('events.index');
        Route::get('events/create', [EventController::class, 'create'])->name('events.create');
        Route::post('events', [EventController::class, 'store'])->name('events.store');
        Route::get('events/{managedEvent}', [EventController::class, 'edit'])->name('events.edit');
        Route::put('events/{managedEvent}', [EventController::class, 'update'])->name('events.update');
        Route::delete('events/{managedEvent}', [EventController::class, 'destroy'])->name('events.destroy');
        Route::post('events/{managedEvent}/submit', [EventController::class, 'submit'])->name('events.submit');
        Route::post('events/{managedEvent}/cancel', [EventController::class, 'cancel'])->name('events.cancel');

        Route::get('promo-codes', [PromoCodeController::class, 'index'])->name('promo-codes.index');
        Route::post('promo-codes', [PromoCodeController::class, 'store'])->name('promo-codes.store');
        Route::put('promo-codes/{promoCode}', [PromoCodeController::class, 'update'])
            ->where('promoCode', '[A-Za-z0-9_-]+')
            ->name('promo-codes.update');
        Route::delete('promo-codes/{promoCode}', [PromoCodeController::class, 'destroy'])
            ->where('promoCode', '[A-Za-z0-9_-]+')
            ->name('promo-codes.destroy');

        Route::scopeBindings()->group(function () {
            Route::post('events/{managedEvent}/images', [EventImageController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('events.images.store');
            Route::post('events/{managedEvent}/images/{image:public_id}/cover', [EventImageController::class, 'cover'])
                ->name('events.images.cover');
            Route::delete('events/{managedEvent}/images/{image:public_id}', [EventImageController::class, 'destroy'])
                ->name('events.images.destroy');

            Route::post('events/{managedEvent}/ticket-types', [TicketTypeController::class, 'store'])
                ->name('events.ticket-types.store');
            Route::put('events/{managedEvent}/ticket-types/{ticketType:public_id}', [TicketTypeController::class, 'update'])
                ->name('events.ticket-types.update');
            Route::delete('events/{managedEvent}/ticket-types/{ticketType:public_id}', [TicketTypeController::class, 'destroy'])
                ->name('events.ticket-types.destroy');
        });
    });
});
