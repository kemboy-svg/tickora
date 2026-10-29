<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks routes behind it until the user has confirmed two-factor
 * authentication. Applied to the platform admin area (and, later, organiser
 * payout settings), as the architecture requires MFA for privileged access.
 *
 *   Route::middleware(['auth', 'verified', 'two-factor'])->group(...)
 *
 * Enforcement is controlled by tickora.require_two_factor: always on in
 * production, off by default in local development so the admin area can be
 * explored without an authenticator app. Permission checks still apply
 * either way.
 */
class RequireTwoFactor
{
    public function __construct(private readonly Config $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $confirmed = $user instanceof User && $user->two_factor_confirmed_at !== null;

        if (! $confirmed && $this->enforced()) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication is required for this area.');
            }

            // Come back here once two-factor is on.
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'The admin area needs two-factor authentication. Turn it on below, then open the page again.',
            ]);

            return redirect()->route('security.edit')->with('two_factor_required', true);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function enforced(): bool
    {
        return (bool) $this->config->get('tickora.require_two_factor', true);
    }
}
