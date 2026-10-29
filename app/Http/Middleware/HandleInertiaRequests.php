<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'nav' => fn (): array => $this->navigation($request),
        ];
    }

    /**
     * Which areas the signed-in user can reach, for the sidebar.
     *
     * @return array{organizer: bool, platform: bool, moderation: bool, verification: bool}
     */
    private function navigation(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ['organizer' => false, 'platform' => false, 'moderation' => false, 'verification' => false];
        }

        return [
            'organizer' => $user->organizerMemberships()->where('status', MembershipStatus::Active)->exists(),
            'platform' => $user->isActive() && $user->isPlatformStaff(),
            'moderation' => $user->hasPlatformPermission(Permission::EventsModerate),
            'verification' => $user->hasPlatformPermission(Permission::OrganizersVerify),
        ];
    }
}
