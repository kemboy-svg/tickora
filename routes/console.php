<?php

use App\Jobs\ExpireInventoryReservations;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('tickora:expire-holds', function () {
    ExpireInventoryReservations::dispatchSync();
    $this->info('Expired checkouts swept.');
})->purpose('Expire ticket holds and checkouts whose time ran out');

// Runs in the scheduler process itself, so no queue worker is needed for
// holds to be released. Availability never depends on it running on time.
Schedule::command('tickora:expire-holds')->everyMinute()->withoutOverlapping();

// For test servers without email: confirm a tester's address by hand.
Artisan::command('tickora:verify-email {email}', function (string $email) {
    $user = User::query()->where('email', $email)->first();

    if ($user === null) {
        $this->error("No account uses {$email}.");

        return 1;
    }

    $user->markEmailAsVerified();
    $this->info("{$email} is confirmed.");

    return 0;
})->purpose("Mark a user's email address as confirmed");
