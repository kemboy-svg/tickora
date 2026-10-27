<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Presenters\EventPresenter;
use App\Models\Category;
use App\Models\Event;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $upcoming = Event::query()
            ->listed()
            ->with(['category', 'venue', 'ticketTypes' => fn ($query) => $query->withHeld()])
            ->orderBy('starts_at')
            ->limit(12)
            ->get();

        return Inertia::render('public/Home', [
            'featured' => $upcoming->take(4)->map(fn (Event $e): array => EventPresenter::card($e))->values()->all(),
            'upcoming' => $upcoming->slice(4)->map(fn (Event $e): array => EventPresenter::card($e))->values()->all(),
            'categories' => Category::query()
                ->withCount(['events' => fn ($query) => $query->listed()])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Category $c): array => [
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'events' => $c->events_count ?? 0,
                ])
                ->all(),
        ]);
    }
}
