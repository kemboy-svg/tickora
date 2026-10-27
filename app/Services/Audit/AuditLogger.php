<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the audit trail for sensitive actions: role and membership
 * changes, staff assignments, publication, refunds, payouts, ticket
 * overrides and admin actions.
 *
 * Metadata is scrubbed of anything that looks like a secret before it is
 * stored. Callers should still pass only what an investigator needs (IDs,
 * old/new values, amounts), never request bodies.
 */
class AuditLogger
{
    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_WEBHOOK = 'webhook';

    /**
     * Keys whose values are never stored, matched case-insensitively as
     * substrings ("stripe_secret", "qr_token", "cardNumber" …).
     */
    private const SENSITIVE_KEYS = [
        'password', 'secret', 'token', 'card', 'cvv', 'cvc', 'iban',
        'account_number', 'accountnumber', 'authorization', 'api_key', 'apikey',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?User $actor = null,
        ?Organizer $organizer = null,
        string $actorType = self::ACTOR_USER,
    ): AuditLog {
        $actor ??= $this->currentUser();

        $log = new AuditLog;
        $log->actor_user_id = $actor?->id;
        $log->actor_type = $actor === null && $actorType === self::ACTOR_USER ? self::ACTOR_SYSTEM : $actorType;
        $log->action = $action;
        $log->organizer_id = $organizer?->id ?? $this->organizerIdOf($subject);
        $log->metadata = $metadata === [] ? null : $this->scrub($metadata);

        if ($subject !== null) {
            $log->subject()->associate($subject);
        }

        $request = $this->currentRequest();

        if ($request !== null) {
            $log->ip_address = $request->ip();
            $log->user_agent = mb_substr((string) $request->userAgent(), 0, 500);
        }

        $log->save();

        return $log;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function organizerIdOf(?Model $subject): ?int
    {
        if ($subject instanceof Organizer) {
            return $subject->id;
        }

        if ($subject === null || ! array_key_exists('organizer_id', $subject->getAttributes())) {
            return null;
        }

        $id = $subject->getAttribute('organizer_id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function currentUser(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }

    private function currentRequest(): ?Request
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return null;
        }

        $request = $this->app->make('request');

        return $request instanceof Request ? $request : null;
    }
}
