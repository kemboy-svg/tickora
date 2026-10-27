<?php

namespace App\Http\Controllers\Organizer;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Middleware\EnsureOrganizerWorkspace;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller extends BaseController
{
    protected function workspace(Request $request): Organizer
    {
        $organizer = $request->attributes->get(EnsureOrganizerWorkspace::ATTRIBUTE);

        abort_unless($organizer instanceof Organizer, 404);

        return $organizer;
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
