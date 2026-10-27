<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks routes behind it until the user has confirmed two-factor
 * authentication. Applied to the platform admin area, and to organiser
 * payout settings, as the architecture requires MFA for privileged access.
 *
 *   Route::middleware(['auth', 'verified', 'two-factor'])->group(...)
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->two_factor_confirmed_at === null) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication is required for this area.');
            }

            return redirect()->route('security.edit')->with(
                'status',
                'Turn on two-factor authentication to continue to this area.',
            );
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
