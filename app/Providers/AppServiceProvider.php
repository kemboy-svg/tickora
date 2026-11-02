<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentGateway;
use App\Services\Media\ImageProcessor;
use App\Services\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);

        // The gateway for new checkouts. Webhooks resolve their adapter by
        // provider name through the manager instead.
        $this->app->bind(
            PaymentGateway::class,
            fn (Application $app): PaymentGateway => $app->make(PaymentGatewayManager::class)->driver(),
        );

        $this->app->bind(ImageProcessor::class, function (): ImageProcessor {
            $edge = config('tickora.image_max_edge', 2400);

            return new ImageProcessor(is_numeric($edge) ? (int) $edge : 2400);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureProxies();
        $this->configureVerificationEmail();
    }

    /**
     * Most hosts put a proxy or load balancer in front of the app that
     * terminates HTTPS. Unless the app trusts it, it sees plain-HTTP
     * requests, so signed links (email verification) fail with "invalid
     * signature" and redirects point at http://. TRUSTED_PROXIES is "*" for
     * a host whose app is reachable only through its proxy, or a
     * comma-separated list of proxy IPs/CIDRs.
     */
    protected function configureProxies(): void
    {
        $proxies = config('tickora.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }

    /**
     * The "confirm your email" message, in Tickora's words rather than the
     * framework default.
     */
    protected function configureVerificationEmail(): void
    {
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject('Confirm your email for '.config('app.name'))
            ->greeting('Welcome to '.config('app.name').'!')
            ->line('Confirm your email address to buy tickets and receive them by email.')
            ->action('Confirm email address', $url)
            ->line('The link works for '.config('auth.verification.expire', 60).' minutes. If it has expired, sign in and ask for a new one.')
            ->line("If you didn't create an account, you can ignore this email.")
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Fail loudly in local development and tests on N+1 lazy loading and
        // on writes to attributes that are not fillable. Hosted environments
        // (staging for testers, production) must not show testers a 500 for
        // these, so there they are logged instead.
        $strict = app()->environment('local', 'testing');
        Model::preventLazyLoading();
        Model::preventSilentlyDiscardingAttributes();

        if (! $strict) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning('Lazy loading '.$relation.' on '.$model::class);
            });
            Model::handleDiscardedAttributeViolationUsing(function (Model $model, array $keys): void {
                Log::warning('Discarded non-fillable '.implode(', ', $keys).' on '.$model::class);
            });
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
