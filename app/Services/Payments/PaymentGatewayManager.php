<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentProvider;
use App\Exceptions\Payments\PaymentProviderException;
use App\Models\Event;
use App\Models\PaymentGatewaySetting;
use App\Services\Payments\Fake\FakePaymentGateway;
use App\Services\Payments\Paystack\PaystackPaymentGateway;
use App\Services\Payments\Stripe\StripePaymentGateway;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;

/**
 * Resolves payment adapters.
 *
 *  - forEvent(): the provider an admin chose for the event, or the
 *    platform default, checked to be switched on and able to charge in
 *    the event's currency. Used for new payments.
 *  - driver(name): any provider that has keys, whether or not it is
 *    switched on, so webhooks, status checks and refunds for payments
 *    already made keep working after an admin changes providers.
 *
 * Stripe and Paystack keys come from the payment_gateways table (managed
 * in the admin area, encrypted at rest); the sandbox is configured in
 * config/tickora.php and refuses to run in production.
 */
class PaymentGatewayManager
{
    private ?FakePaymentGateway $fake = null;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly Http $http,
    ) {}

    public function driver(?string $name = null): PaymentGateway
    {
        $provider = PaymentProvider::tryFrom($name ?? $this->defaultDriver())
            ?? throw PaymentProviderException::unknownProvider((string) $name);

        if ($provider === PaymentProvider::Fake) {
            return $this->fake ??= $this->makeFake();
        }

        $settings = PaymentGatewaySetting::for($provider);

        if ($settings === null || ! $settings->hasSecretKey()) {
            throw PaymentProviderException::notConfigured($provider->label());
        }

        return $this->make($provider, $settings);
    }

    /**
     * The gateway new payments for this event go through:
     *
     *  1. the provider an admin chose for the event, which must be ready
     *    (the sandbox choice is ignored in production, where it can't run);
     *  2. otherwise the admin's default, if it is ready and can charge in
     *    the event's currency;
     *  3. otherwise any switched-on provider that can;
     *  4. otherwise, outside production, the configured fallback (the
     *    sandbox).
     */
    public function forEvent(Event $event): PaymentGateway
    {
        $chosen = $event->payment_provider;

        if ($chosen === PaymentProvider::Fake && $this->app->isProduction()) {
            $chosen = null;
        }

        if ($chosen !== null) {
            return $this->ready($chosen, $event->currency);
        }

        $candidates = $this->usableProviders();
        $default = PaymentGatewaySetting::query()->where('is_default', true)->first();

        if ($default !== null && $default->isUsable()) {
            // The default first, then the others.
            usort($candidates, fn (PaymentProvider $a, PaymentProvider $b): int => ($b === $default->provider) <=> ($a === $default->provider));
        }

        foreach ($candidates as $provider) {
            $gateway = $this->driver($provider->value);

            if ($gateway->supportsCurrency($event->currency)) {
                return $gateway;
            }
        }

        if ($this->app->isProduction()) {
            throw $candidates === []
                ? new PaymentProviderException('No payment provider is switched on: an admin needs to add live keys in Payment settings.')
                : PaymentProviderException::unsupportedCurrency(
                    implode(', ', array_map(fn (PaymentProvider $p): string => $p->label(), $candidates)),
                    $event->currency,
                );
        }

        $fallback = PaymentProvider::tryFrom($this->configuredFallback())
            ?? throw PaymentProviderException::unknownProvider($this->configuredFallback());

        return $this->ready($fallback, $event->currency);
    }

    /**
     * The provider used when an event has none chosen, as shown to admins:
     * the admin's default if it is ready, otherwise the first switched-on
     * provider, otherwise the configured fallback (never the sandbox in
     * production).
     */
    public function defaultDriver(): string
    {
        $default = PaymentGatewaySetting::query()->where('is_default', true)->first();

        if ($default !== null && $default->isUsable()) {
            return $default->provider->value;
        }

        $configured = $this->configuredFallback();

        if (! $this->app->isProduction() || $configured !== PaymentProvider::Fake->value) {
            return $configured;
        }

        return ($this->usableProviders()[0] ?? PaymentProvider::Stripe)->value;
    }

    /**
     * Stripe and Paystack, when switched on with keys.
     *
     * @return list<PaymentProvider>
     */
    public function usableProviders(): array
    {
        $usable = PaymentGatewaySetting::query()
            ->where('enabled', true)
            ->get()
            ->filter(fn (PaymentGatewaySetting $s): bool => $s->isUsable())
            ->map(fn (PaymentGatewaySetting $s): PaymentProvider => $s->provider)
            ->all();

        return array_values(array_filter(
            PaymentProvider::configurable(),
            fn (PaymentProvider $p): bool => in_array($p, $usable, true),
        ));
    }

    /**
     * A provider that must be switched on, with keys, and able to charge
     * in the currency.
     */
    private function ready(PaymentProvider $provider, string $currency): PaymentGateway
    {
        if ($provider !== PaymentProvider::Fake) {
            $settings = PaymentGatewaySetting::for($provider);

            if ($settings === null || ! $settings->hasSecretKey()) {
                throw PaymentProviderException::notConfigured($provider->label());
            }

            if (! $settings->enabled) {
                throw PaymentProviderException::disabled($provider->label());
            }
        }

        $gateway = $this->driver($provider->value);

        if (! $gateway->supportsCurrency($currency)) {
            throw PaymentProviderException::unsupportedCurrency($provider->label(), $currency);
        }

        return $gateway;
    }

    private function configuredFallback(): string
    {
        $configured = $this->config->get('tickora.payments.default', 'fake');

        return is_string($configured) && $configured !== '' ? $configured : 'fake';
    }

    /**
     * Currencies a provider can charge in (from config).
     *
     * @return list<string>
     */
    public function currenciesFor(PaymentProvider $provider): array
    {
        $currencies = $this->config->get("tickora.payments.providers.{$provider->value}.currencies", []);

        return is_array($currencies)
            ? array_values(array_map(fn ($c): string => strtoupper((string) $c), $currencies))
            : [];
    }

    /**
     * @return list<string>
     */
    public function configuredProviders(): array
    {
        return array_map(
            fn (PaymentProvider $p): string => $p->value,
            PaymentProvider::selectable($this->app->isProduction()),
        );
    }

    private function make(PaymentProvider $provider, PaymentGatewaySetting $settings): PaymentGateway
    {
        $tolerance = $this->config->get('tickora.payments.providers.stripe.webhook_tolerance_seconds', 300);

        return match ($provider) {
            PaymentProvider::Stripe => new StripePaymentGateway(
                http: $this->http,
                secretKey: (string) $settings->secret_key,
                webhookSecret: $settings->webhook_secret,
                currencies: $this->currenciesFor($provider),
                toleranceSeconds: is_int($tolerance) ? $tolerance : 300,
            ),
            PaymentProvider::Paystack => new PaystackPaymentGateway(
                http: $this->http,
                secretKey: (string) $settings->secret_key,
                currencies: $this->currenciesFor($provider),
            ),
            PaymentProvider::Fake => $this->makeFake(),
        };
    }

    private function makeFake(): FakePaymentGateway
    {
        if ($this->app->isProduction()) {
            throw PaymentProviderException::disabledInProduction('fake');
        }

        $settings = $this->config->get('tickora.payments.providers.fake', []);
        $settings = is_array($settings) ? $settings : [];

        $secret = $settings['webhook_secret'] ?? null;
        $tolerance = $settings['webhook_tolerance_seconds'] ?? 300;

        return new FakePaymentGateway(
            cache: $this->cache->store(),
            webhookSecret: is_string($secret) ? $secret : null,
            toleranceSeconds: is_int($tolerance) ? $tolerance : 300,
            currencies: $this->currenciesFor(PaymentProvider::Fake),
            hostedPageUrl: ($settings['hosted_page'] ?? false) === true ? url('/sandbox/payments') : null,
        );
    }
}
