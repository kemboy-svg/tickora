<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app's counterpart of EnsureUserIsActive: a suspended user's app token
 * is revoked on their next request.
 */
class EnsureApiUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            $user->currentAccessToken()->delete();

            abort(403, 'This account has been suspended. Contact support for help.');
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
