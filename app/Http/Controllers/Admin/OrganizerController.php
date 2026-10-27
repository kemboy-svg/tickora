<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ChangeOrganizerStatus;
use App\Enums\OrganizerStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organiser applications and verification for platform staff.
 */
class OrganizerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPlatformPermission(Permission::OrganizersVerify), 403);

        $status = $request->string('status', OrganizerStatus::Pending->value)->toString();

        $organizers = Organizer::query()
            ->with('owner')
            ->withCount('events')
            ->when(
                OrganizerStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->orderBy('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Organizer $organizer): array => [
                'id' => $organizer->public_id,
                'name' => $organizer->name,
                'slug' => $organizer->slug,
                'status' => $organizer->status->value,
                'country_code' => $organizer->country_code,
                'default_currency' => $organizer->default_currency,
                'owner_name' => $organizer->owner->name,
                'owner_email' => $organizer->owner->email,
                'support_email' => $organizer->support_email,
                'description' => $organizer->description,
                'events_count' => $organizer->events_count ?? 0,
                'created_label' => $organizer->created_at?->diffForHumans(),
            ]);

        return Inertia::render('admin/organizers/Index', [
            'organizers' => $organizers,
            'filters' => ['status' => $status],
            'statuses' => array_map(fn (OrganizerStatus $s): string => $s->value, OrganizerStatus::cases()),
        ]);
    }

    public function update(Request $request, Organizer $organizer, ChangeOrganizerStatus $change): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrganizerStatus::class)],
            'reason' => ['nullable', 'required_unless:status,verified', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $to = OrganizerStatus::from($validated['status']);
        $change->handle($user, $organizer, $to, $validated['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$organizer->name} is now {$to->value}."]);

        return back();
    }
}
