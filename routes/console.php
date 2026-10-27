<?php

use App\Jobs\ExpireInventoryReservations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('tickora:expire-holds', function () {
    ExpireInventoryReservations::dispatchSync();
    $this->info('Expired checkouts swept.');
})->purpose('Expire ticket holds and checkouts whose time ran out');

// Runs in the scheduler process itself, so no queue worker is needed for
// holds to be released. Availability never depends on it running on time.
Schedule::command('tickora:expire-holds')->everyMinute()->withoutOverlapping();
