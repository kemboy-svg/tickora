<?php

namespace App\Http\Controllers\Organizer;

use App\Actions\Organizers\CreateOrganizer;
use App\Http\Requests\Organizer\OnboardingRequest;
use App\Support\Currency;
use App\Support\CurrentOrganizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Become an organiser": a signed-in user creates their organiser. It starts
 * pending verification and can prepare drafts straight away.
 */
class OnboardingController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('organizer/Onboarding', [
            'currencies' => Currency::allowed(),
            'hasOrganizers' => app(CurrentOrganizer::class)->available($this->actor($request))->isNotEmpty(),
        ]);
    }

    public function store(OnboardingRequest $request, CreateOrganizer $createOrganizer, CurrentOrganizer $current): RedirectResponse
    {
        $user = $this->actor($request);
        $organizer = $createOrganizer->handle($user, $request->toData());

        $current->switchTo($user, $organizer->public_id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$organizer->name} is set up. You can create events now; publishing opens once we verify you.",
        ]);

        return to_route('organizer.events.index');
    }
}
