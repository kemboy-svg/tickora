<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentProvider;
use App\Enums\Permission;
use App\Exceptions\Payments\PaymentProviderException;
use App\Http\Controllers\Controller;
use App\Models\PaymentGatewaySetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform admins connect Stripe and Paystack here. Keys are write-only:
 * the page shows whether a key is set and its last four characters, and
 * leaving a key field blank keeps the saved one. Every change is audited
 * (which fields changed, never their values).
 */
class PaymentSettingsController extends Controller
{
    public function index(Request $request, PaymentGatewayManager $gateways): Response
    {
        $this->admin($request);

        $settings = PaymentGatewaySetting::query()->with('editor:id,name')->get()->keyBy(fn (PaymentGatewaySetting $s) => $s->provider->value);

        $providers = array_map(function (PaymentProvider $provider) use ($settings, $gateways): array {
            $row = $settings->get($provider->value);

            return [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'enabled' => $row !== null && $row->enabled,
                'is_default' => $row !== null && $row->is_default,
                'usable' => $row?->isUsable() ?? false,
                'mode' => $row?->mode(),
                'public_key' => $row?->public_key,
                'secret_key_hint' => PaymentGatewaySetting::mask($row?->secret_key),
                'webhook_secret_hint' => PaymentGatewaySetting::mask($row?->webhook_secret),
                'uses_webhook_secret' => $provider === PaymentProvider::Stripe,
                'webhook_url' => route('webhooks.payments', $provider->value),
                'currencies' => $gateways->currenciesFor($provider),
                'updated_label' => $row?->updated_at?->diffForHumans(),
                'updated_by' => $row?->editor?->name,
            ];
        }, PaymentProvider::configurable());

        return Inertia::render('admin/payments/Index', [
            'providers' => $providers,
            'default_provider' => $gateways->defaultDriver(),
            'sandbox_available' => ! app()->isProduction(),
        ]);
    }

    public function update(Request $request, string $provider, AuditLogger $audit): RedirectResponse
    {
        $admin = $this->admin($request);
        $provider = PaymentProvider::tryFrom($provider);
        abort_unless($provider !== null && in_array($provider, PaymentProvider::configurable(), true), 404);

        $isStripe = $provider === PaymentProvider::Stripe;

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'public_key' => ['nullable', 'string', 'max:255', 'regex:/^pk_(test|live)_[A-Za-z0-9_]+$/'],
            'secret_key' => ['nullable', 'string', 'max:255', $isStripe ? 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9_]+$/' : 'regex:/^sk_(test|live)_[A-Za-z0-9_]+$/'],
            'webhook_secret' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9_+\/=]+$/'],
        ], [
            'public_key.regex' => 'This does not look like a public key (it starts with pk_test_ or pk_live_).',
            'secret_key.regex' => $isStripe
                ? 'This does not look like a Stripe secret key (sk_test_…, sk_live_… or a restricted rk_ key).'
                : 'This does not look like a Paystack secret key (sk_test_… or sk_live_…).',
            'webhook_secret.regex' => 'Stripe webhook signing secrets start with whsec_.',
        ]);

        $setting = PaymentGatewaySetting::for($provider) ?? new PaymentGatewaySetting;
        $setting->provider = $provider;

        $changed = [];

        $secret = $validated['secret_key'] ?? null;
        if (is_string($secret) && $secret !== '') {
            $setting->secret_key = $secret;
            $changed[] = 'api key';
        }

        $webhook = $validated['webhook_secret'] ?? null;
        if ($isStripe && is_string($webhook) && $webhook !== '') {
            $setting->webhook_secret = $webhook;
            $changed[] = 'webhook signing';
        }

        $public = $validated['public_key'] ?? null;
        if ($public !== $setting->public_key) {
            $setting->public_key = is_string($public) && $public !== '' ? $public : null;
            $changed[] = 'public key';
        }

        $enabled = (bool) $validated['enabled'];
        if ($enabled && ! $setting->hasSecretKey()) {
            throw ValidationException::withMessages(['secret_key' => "Add the {$provider->label()} secret key before switching it on."]);
        }

        if ($setting->public_key !== null && $setting->hasSecretKey()
            && str_contains($setting->public_key, '_live_') !== str_contains((string) $setting->secret_key, '_live_')) {
            throw ValidationException::withMessages(['public_key' => 'The public and secret keys must both be test keys or both be live keys.']);
        }

        if ($enabled !== $setting->enabled) {
            $changed[] = $enabled ? 'switched on' : 'switched off';
        }

        $setting->enabled = $enabled;

        if (! $enabled) {
            $setting->is_default = false;
        }

        $setting->updated_by = $admin->id;
        $setting->save();

        $audit->record('payment_gateway.updated', null, [
            'provider' => $provider->value,
            'changed' => $changed,
            'mode' => $setting->mode(),
        ], actor: $admin);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->label()} settings saved."]);

        return to_route('admin.payments.index');
    }

    public function test(Request $request, string $provider, PaymentGatewayManager $gateways): RedirectResponse
    {
        $this->admin($request);
        $provider = PaymentProvider::tryFrom($provider);
        abort_unless($provider !== null && in_array($provider, PaymentProvider::configurable(), true), 404);

        try {
            $gateways->driver($provider->value)->verifyCredentials();
            $mode = PaymentGatewaySetting::for($provider)?->mode();
            Inertia::flash('toast', ['type' => 'success', 'message' => "Connected to {$provider->label()}".($mode !== null ? " ({$mode} mode)." : '.')]);
        } catch (PaymentProviderException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return to_route('admin.payments.index');
    }

    /**
     * The provider for events that don't name one.
     */
    public function setDefault(Request $request, AuditLogger $audit): RedirectResponse
    {
        $admin = $this->admin($request);

        $allowed = array_map(fn (PaymentProvider $p): string => $p->value, PaymentProvider::selectable(app()->isProduction()));
        $value = $request->validate(['provider' => ['required', Rule::in($allowed)]])['provider'];
        $provider = PaymentProvider::from(is_string($value) ? $value : '');

        if ($provider !== PaymentProvider::Fake) {
            $setting = PaymentGatewaySetting::for($provider);

            if ($setting === null || ! $setting->isUsable()) {
                throw ValidationException::withMessages(['provider' => "Switch {$provider->label()} on, with its keys, before making it the default."]);
            }
        }

        DB::transaction(function () use ($provider): void {
            PaymentGatewaySetting::query()->where('is_default', true)->update(['is_default' => false]);

            if ($provider !== PaymentProvider::Fake) {
                PaymentGatewaySetting::query()->where('provider', $provider->value)->update(['is_default' => true]);
            }
        });

        $audit->record('payment_gateway.default_changed', null, ['provider' => $provider->value], actor: $admin);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->label()} is now the default for events."]);

        return to_route('admin.payments.index');
    }

    private function admin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasPlatformPermission(Permission::PlatformSettingsManage), 403);

        return $user;
    }
}
