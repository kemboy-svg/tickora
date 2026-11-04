<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Sign-up and sign-in for the mobile app. Each signed-in phone gets its own
 * token (named after the device), revoked when the app signs out.
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function register(Request $request, CreatesNewUsers $creator): JsonResponse
    {
        $device = $this->deviceName($request);

        // The same rules and checks as signing up on the website.
        $created = $creator->create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'password_confirmation' => $request->string('password_confirmation')->toString(),
        ]);
        $user = User::query()->findOrFail($created->getKey());

        // Sends the confirmation email.
        event(new Registered($user));

        return response()->json([
            'token' => $user->createToken($device)->plainTextToken,
            'user' => self::user($user),
        ], 201);
    }

    public function login(Request $request, TwoFactorAuthenticationProvider $twoFactor): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);
        $device = $this->deviceName($request);

        $key = 'api-login:'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account has been suspended. Contact support for help.']);
        }

        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null) {
            $code = $credentials['code'] ?? null;

            if (! is_string($code) || $code === '') {
                return response()->json([
                    'message' => 'Enter the code from your authenticator app.',
                    'two_factor_required' => true,
                ], 422);
            }

            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

            if (! is_string($secret) || ! $twoFactor->verify($secret, $code)) {
                RateLimiter::hit($key);

                throw ValidationException::withMessages(['code' => 'That code is not valid.']);
            }
        }

        RateLimiter::clear($key);

        return response()->json([
            'token' => $user->createToken($device)->plainTextToken,
            'user' => self::user($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->current($request)->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::user($this->current($request))]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $this->current($request);

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email address is already confirmed.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'We sent a new confirmation link to '.$user->email.'.']);
    }

    /**
     * @return array{name: string, email: string, email_verified: bool}
     */
    public static function user(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    private function deviceName(Request $request): string
    {
        $name = trim($request->string('device_name')->toString());

        return $name === '' ? 'Tickora app' : mb_substr($name, 0, 100);
    }

    private function current(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
