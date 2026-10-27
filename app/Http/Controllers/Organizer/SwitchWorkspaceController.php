<?php

namespace App\Http\Controllers\Organizer;

use App\Support\CurrentOrganizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchWorkspaceController extends Controller
{
    public function __invoke(Request $request, CurrentOrganizer $current): RedirectResponse
    {
        $request->validate(['organizer' => ['required', 'string']]);

        abort_unless($current->switchTo($this->actor($request), $request->string('organizer')->toString()), 404);

        return to_route('organizer.events.index');
    }
}
