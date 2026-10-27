<?php

namespace App\Http\Middleware;

use App\Models\Organizer;
use App\Models\User;
use App\Support\CurrentOrganizer;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the organiser workspace for /organizer routes. Users without an
 * organiser are sent to onboarding. The resolved organiser is available to
 * controllers as the "organizer" request attribute and shared with the
 * frontend for the workspace switcher.
 */
class EnsureOrganizerWorkspace
{
    public const ATTRIBUTE = 'organizer';

    public function __construct(private readonly CurrentOrganizer $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $organizer = $this->current->resolve($user);

        if ($organizer === null) {
            return redirect()->route('organizer.onboarding');
        }

        $request->attributes->set(self::ATTRIBUTE, $organizer);

        Inertia::share('workspace', fn (): array => [
            'current' => [
                'id' => $organizer->public_id,
                'name' => $organizer->name,
                'status' => $organizer->status->value,
                'can_sell' => $organizer->canSell(),
                'role' => $user->roleIn($organizer)?->value,
            ],
            'available' => $this->current->available($user)
                ->map(fn (Organizer $o): array => ['id' => $o->public_id, 'name' => $o->name])
                ->values()
                ->all(),
        ]);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
