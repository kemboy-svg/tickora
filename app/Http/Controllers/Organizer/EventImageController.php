<?php

namespace App\Http\Controllers\Organizer;

use App\Actions\Events\ManageEventImages;
use App\Http\Requests\Organizer\EventRequest;
use App\Models\Event;
use App\Models\EventImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;

/**
 * An event's photos. Images are bound with scoped bindings, so an image of
 * another event (or organiser) is a 404.
 */
class EventImageController extends Controller
{
    public function store(Request $request, Event $managedEvent, ManageEventImages $images): RedirectResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.EventRequest::maxImages()],
            'images.*' => EventRequest::imageRules(),
        ], EventRequest::imageMessages());

        $files = $request->file('images');
        $files = is_array($files) ? array_values(array_filter($files, fn ($f): bool => $f instanceof UploadedFile)) : [];

        $added = $images->attach($this->actor($request), $managedEvent, $images->prepare($files));

        Inertia::flash('toast', ['type' => 'success', 'message' => count($added) === 1 ? 'Image added.' : count($added).' images added.']);

        return to_route('organizer.events.edit', $managedEvent);
    }

    public function cover(Request $request, Event $managedEvent, EventImage $image, ManageEventImages $images): RedirectResponse
    {
        $images->makeCover($this->actor($request), $managedEvent, $image);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cover image updated.']);

        return to_route('organizer.events.edit', $managedEvent);
    }

    public function destroy(Request $request, Event $managedEvent, EventImage $image, ManageEventImages $images): RedirectResponse
    {
        $images->remove($this->actor($request), $managedEvent, $image);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Image removed.']);

        return to_route('organizer.events.edit', $managedEvent);
    }
}
