<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Presenters\EventPresenter;
use App\Models\Event;
use App\Models\Organizer;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public organiser profile: who they are and their upcoming public events.
 * Only verified organisers have a public page.
 */
class OrganizerController extends Controller
{
    public function show(Organizer $organizer): Response
    {
        abort_unless($organizer->canSell(), 404);

        $events = Event::query()
            ->listed()
            ->forOrganizer($organizer)
            ->with(['category', 'venue', 'ticketTypes'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $e): array => EventPresenter::card($e))
            ->all();

        return Inertia::render('public/organizers/Show', [
            'organizer' => [
                'name' => $organizer->name,
                'slug' => $organizer->slug,
                'description' => $organizer->description,
                'country_code' => $organizer->country_code,
                'support_email' => $organizer->support_email,
                'verified' => true,
            ],
            'events' => $events,
        ]);
    }
}
