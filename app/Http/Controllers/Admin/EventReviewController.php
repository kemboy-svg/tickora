<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Events\ChangeEventStatus;
use App\Enums\EventStatus;
use App\Enums\PaymentProvider;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EventPresenter;
use App\Models\Event;
use App\Models\PaymentGatewaySetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform moderators review submitted events: approve to publish, or send
 * back to the organiser with notes.
 */
class EventReviewController extends Controller
{
    public function index(Request $request, PaymentGatewayManager $gateways): Response
    {
        $user = $this->moderator($request);
        $status = $request->string('status')->toString() === 'published' ? EventStatus::Published : EventStatus::PendingReview;

        $events = Event::query()
            ->where('status', $status)
            ->with(['organizer', 'venue', 'category', 'ticketTypes', 'images'])
            ->when(
                $status === EventStatus::Published,
                fn ($q) => $q->where('ends_at', '>', now())->orderBy('starts_at'),
                fn ($q) => $q->orderBy('submitted_at'),
            )
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Event $event): array => [
                ...EventPresenter::detail($event),
                'submitted_label' => $event->submitted_at?->diffForHumans(),
                'organizer_status' => $event->organizer->status->value,
                'readiness' => $event->status === EventStatus::PendingReview
                    ? app(ChangeEventStatus::class)->readinessProblems($event)
                    : [],
                'payment_provider' => $event->payment_provider?->value,
                'currency' => $event->currency,
            ]);

        return Inertia::render('admin/events/Index', [
            'events' => $events,
            'filters' => ['status' => $status->value],
            'payments' => [
                'can_manage' => $user->hasPlatformPermission(Permission::PlatformSettingsManage),
                'default' => $gateways->defaultDriver(),
                'options' => $this->providerOptions($gateways),
            ],
        ]);
    }

    /**
     * Which provider takes payments for this event. Null means the
     * platform default. Only providers that are switched on and can charge
     * in the event's currency may be chosen.
     */
    public function paymentProvider(Request $request, Event $event, PaymentGatewayManager $gateways, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPlatformPermission(Permission::PlatformSettingsManage), 403);

        $allowed = array_map(fn (PaymentProvider $p): string => $p->value, PaymentProvider::selectable(app()->isProduction()));
        $value = $request->validate(['provider' => ['nullable', Rule::in($allowed)]])['provider'] ?? null;
        $provider = is_string($value) ? PaymentProvider::from($value) : null;

        if ($provider !== null && $provider !== PaymentProvider::Fake) {
            $setting = PaymentGatewaySetting::for($provider);

            if ($setting === null || ! $setting->isUsable()) {
                throw ValidationException::withMessages(['provider' => "{$provider->label()} is not switched on yet. Add its keys under Payment settings."]);
            }
        }

        if ($provider !== null && ! in_array($event->currency, $gateways->currenciesFor($provider), true)) {
            throw ValidationException::withMessages(['provider' => "{$provider->label()} can't take payments in {$event->currency}."]);
        }

        $before = $event->payment_provider?->value;
        $event->payment_provider = $provider;
        $event->save();

        $audit->record('event.payment_provider_changed', $event, [
            'before' => $before,
            'after' => $provider?->value,
        ], actor: $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $provider === null
                ? "\"{$event->title}\" now uses the platform default."
                : "\"{$event->title}\" now takes payments with {$provider->label()}.",
        ]);

        return back();
    }

    /**
     * @return list<array{value: string, label: string, usable: bool, currencies: list<string>}>
     */
    private function providerOptions(PaymentGatewayManager $gateways): array
    {
        $settings = PaymentGatewaySetting::query()->get()->keyBy(fn (PaymentGatewaySetting $s) => $s->provider->value);

        return array_map(fn (PaymentProvider $p): array => [
            'value' => $p->value,
            'label' => $p->label(),
            'usable' => $p === PaymentProvider::Fake || ($settings->get($p->value)?->isUsable() ?? false),
            'currencies' => $gateways->currenciesFor($p),
        ], PaymentProvider::selectable(app()->isProduction()));
    }

    public function approve(Request $request, Event $event, ChangeEventStatus $status): RedirectResponse
    {
        $status->approve($this->moderator($request), $event);

        Inertia::flash('toast', ['type' => 'success', 'message' => "\"{$event->title}\" is now live."]);

        return to_route('admin.events.index');
    }

    public function reject(Request $request, Event $event, ChangeEventStatus $status): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['required', 'string', 'min:10', 'max:2000']]);

        $status->reject($this->moderator($request), $event, $validated['notes']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent back to the organiser with your notes.']);

        return to_route('admin.events.index');
    }

    private function moderator(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasPlatformPermission(Permission::EventsModerate), 403);

        return $user;
    }
}
