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
     * The gateway new payments for this event go through.
     */
    public function forEvent(Event $event): PaymentGateway
    {
        $provider = $event->payment_provider ?? PaymentProvider::tryFrom($this->defaultDriver());

        if ($provider === null) {
            throw PaymentProviderException::unknownProvider($this->defaultDriver());
        }

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

        if (! $gateway->supportsCurrency($event->currency)) {
            throw PaymentProviderException::unsupportedCurrency($provider->label(), $event->currency);
        }

        return $gateway;
    }

    /**
     * The provider used when an event has none chosen: the admin's
     * default if it is ready, otherwise the configured fallback (the
     * sandbox outside production).
     */
    public function defaultDriver(): string
    {
        $default = PaymentGatewaySetting::query()->where('is_default', true)->first();

        if ($default !== null && $default->isUsable()) {
            return $default->provider->value;
        }

        $configured = $this->config->get('tickora.payments.default', 'fake');

        return is_string($configured) ? $configured : 'fake';
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
