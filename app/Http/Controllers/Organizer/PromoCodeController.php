<?php

namespace App\Http\Controllers\Organizer;

use App\Actions\PromoCodes\ManagePromoCodes;
use App\Enums\EventStatus;
use App\Http\Requests\Organizer\PromoCodeRequest;
use App\Models\Event;
use App\Models\PromoCode;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current workspace's promo codes. Codes are looked up within the
 * workspace by their code (unique per organiser), so another organiser's
 * code is a 404 and no internal ids reach the browser.
 */
class PromoCodeController extends Controller
{
    public function index(Request $request): Response
    {
        $organizer = $this->workspace($request);
        Gate::authorize('managePromoCodes', $organizer);

        $codes = PromoCode::query()
            ->forOrganizer($organizer)
            ->with('event')
            ->latest('id')
            ->get()
            ->map(fn (PromoCode $promo): array => [
                'code' => $promo->code,
                'discount_label' => $promo->discount_type === 'percent'
                    ? rtrim(rtrim(number_format($promo->discount_value / 100, 2, '.', ''), '0'), '.').'% off'
                    : Money::ofMinor($promo->discount_value, $promo->currency ?? $organizer->default_currency)->format().' off',
                'event' => $promo->event?->title,
                'starts_at' => $promo->starts_at?->toIso8601String(),
                'ends_at' => $promo->ends_at?->toIso8601String(),
                'redemptions' => $promo->redemptions_count,
                'max_redemptions' => $promo->max_redemptions,
                'max_per_customer' => $promo->max_per_customer,
                'is_active' => $promo->is_active,
            ])
            ->values()
            ->all();

        $events = Event::query()
            ->forOrganizer($organizer)
            ->whereNotIn('status', [EventStatus::Cancelled, EventStatus::Completed])
            ->orderBy('starts_at')
            ->get(['id', 'public_id', 'title', 'currency'])
            ->map(fn (Event $event): array => [
                'id' => $event->public_id,
                'title' => $event->title,
                'currency' => $event->currency,
            ])
            ->values()
            ->all();

        return Inertia::render('organizer/promo-codes/Index', [
            'codes' => $codes,
            'events' => $events,
            'defaultCurrency' => $organizer->default_currency,
        ]);
    }

    public function store(PromoCodeRequest $request, ManagePromoCodes $manage): RedirectResponse
    {
        $organizer = $this->workspace($request);

        $promo = $manage->create($this->actor($request), $organizer, $request->toData($organizer));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Promo code {$promo->code} created."]);

        return to_route('organizer.promo-codes.index');
    }

    public function update(Request $request, string $promoCode, ManagePromoCodes $manage): RedirectResponse
    {
        $organizer = $this->workspace($request);
        $promo = PromoCode::query()->forOrganizer($organizer)->where('code', strtoupper($promoCode))->firstOrFail();

        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        $manage->setActive($this->actor($request), $organizer, $promo, (bool) $active);

        Inertia::flash('toast', ['type' => 'success', 'message' => $active ? 'Promo code activated.' : 'Promo code paused.']);

        return to_route('organizer.promo-codes.index');
    }

    public function destroy(Request $request, string $promoCode, ManagePromoCodes $manage): RedirectResponse
    {
        $organizer = $this->workspace($request);
        $promo = PromoCode::query()->forOrganizer($organizer)->where('code', strtoupper($promoCode))->firstOrFail();

        $manage->delete($this->actor($request), $organizer, $promo);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Promo code deleted.']);

        return to_route('organizer.promo-codes.index');
    }
}
