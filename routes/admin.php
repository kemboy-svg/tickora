<?php

use App\Http\Controllers\Admin\EventReviewController;
use App\Http\Controllers\Admin\OrganizerController;
use Illuminate\Support\Facades\Route;

/*
| Platform administration. Every route requires two-factor authentication;
| each controller additionally checks the platform permission it needs.
*/
Route::middleware(['auth', 'verified', 'two-factor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('events', [EventReviewController::class, 'index'])->name('events.index');
    Route::post('events/{event}/approve', [EventReviewController::class, 'approve'])->name('events.approve');
    Route::post('events/{event}/reject', [EventReviewController::class, 'reject'])->name('events.reject');

    Route::get('organizers', [OrganizerController::class, 'index'])->name('organizers.index');
    Route::patch('organizers/{organizer}/status', [OrganizerController::class, 'update'])->name('organizers.status');
});
