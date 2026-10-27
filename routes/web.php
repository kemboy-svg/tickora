<?php

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
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/organizer.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
