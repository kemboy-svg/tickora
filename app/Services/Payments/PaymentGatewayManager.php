<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Exceptions\Payments\PaymentProviderException;
use App\Services\Payments\Fake\FakePaymentGateway;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves payment adapters by provider name ("fake", later e.g. "stripe").
 * Webhook routes resolve by the {provider} segment; new checkouts use the
 * configured default.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly CacheFactory $cache,
    ) {}

    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= $this->defaultDriver();

        return $this->resolved[$name] ??= $this->make($name);
    }

    public function defaultDriver(): string
    {
        $default = $this->config->get('tickora.payments.default', 'fake');

        return is_string($default) ? $default : 'fake';
    }

    /**
     * @return list<string>
     */
    public function configuredProviders(): array
    {
        $providers = $this->config->get('tickora.payments.providers', []);

        return is_array($providers) ? array_map('strval', array_keys($providers)) : [];
    }

    private function make(string $name): PaymentGateway
    {
        $settings = $this->config->get("tickora.payments.providers.{$name}");

        if (! is_array($settings)) {
            throw PaymentProviderException::unknownProvider($name);
        }

        return match ($name) {
            'fake' => $this->makeFake($settings),
            default => throw PaymentProviderException::unknownProvider($name),
        };
    }

    /**
     * @param  array<mixed>  $settings
     */
    private function makeFake(array $settings): FakePaymentGateway
    {
        if ($this->app->isProduction()) {
            throw PaymentProviderException::disabledInProduction('fake');
        }

        $secret = $settings['webhook_secret'] ?? null;
        $tolerance = $settings['webhook_tolerance_seconds'] ?? 300;
        $currencies = $settings['currencies'] ?? [];

        return new FakePaymentGateway(
            cache: $this->cache->store(),
            webhookSecret: is_string($secret) ? $secret : null,
            toleranceSeconds: is_int($tolerance) ? $tolerance : 300,
            currencies: is_array($currencies)
                ? array_values(array_map(fn ($c): string => strtoupper((string) $c), $currencies))
                : [],
        );
    }
}
