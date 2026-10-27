<?php

namespace App\Actions\Organizers;

use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use App\Enums\OrganizerStatus;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turn a user into the owner of a new organiser. The organiser starts as
 * "pending": it can prepare drafts but cannot publish until verified.
 */
class CreateOrganizer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, country_code: string, default_currency: string, description?: string|null, support_email?: string|null}  $data
     */
    public function handle(User $owner, array $data): Organizer
    {
        $currency = Currency::assertAllowed($data['default_currency']);

        return DB::transaction(function () use ($owner, $data, $currency): Organizer {
            $organizer = new Organizer([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'country_code' => strtoupper($data['country_code']),
                'default_currency' => $currency,
                'support_email' => $data['support_email'] ?? $owner->email,
            ]);
            $organizer->owner_user_id = $owner->id;
            $organizer->status = OrganizerStatus::Pending;
            $organizer->save();

            $membership = new OrganizerMember;
            $membership->organizer_id = $organizer->id;
            $membership->user_id = $owner->id;
            $membership->role = OrganizerRole::Owner;
            $membership->status = MembershipStatus::Active;
            $membership->joined_at = now();
            $membership->save();

            $this->audit->record('organizer.created', $organizer, [
                'name' => $organizer->name,
                'country_code' => $organizer->country_code,
                'default_currency' => $organizer->default_currency,
            ], actor: $owner);

            return $organizer;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organiser';
        $slug = $base;

        while (Organizer::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
