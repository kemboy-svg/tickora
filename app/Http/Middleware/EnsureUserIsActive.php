<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a suspended user on their next request, so a
 * suspension takes effect immediately rather than when the session expires.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'This account has been suspended.');
            }

            return redirect()->route('login')->withErrors([
                'email' => 'This account has been suspended. Contact support for help.',
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
