<?php

namespace App\Providers;

use App\Support\Billing\Gateway\FakePaymentGateway;
use App\Support\Billing\Gateway\PaymentGateway;
use App\Support\Billing\Gateway\PaystackGateway;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Tenancy::class);

        $this->registerPaymentGateway();
    }

    /**
     * Without Paystack credentials the app runs against the fake gateway, so
     * local development and tests work out of the box and a misconfigured
     * production deploy fails loudly at boot rather than silently taking
     * payments nowhere.
     */
    protected function registerPaymentGateway(): void
    {
        $this->app->singleton(PaymentGateway::class, function (): PaymentGateway {
            $secret = config('services.paystack.secret');

            if (blank($secret)) {
                throw_if(app()->isProduction(), new RuntimeException(
                    'PAYSTACK_SECRET_KEY must be set in production.',
                ));

                return new FakePaymentGateway;
            }

            return new PaystackGateway($secret, config('services.paystack.base_url'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Per-camera rate limit for the Hikvision HTTP Listening webhook. A busy
     * LPR camera at rush hour emits a handful of events per minute; 60/sec
     * is well above that but far below what an unauthenticated flooder could
     * push through, and cheap enough to burst on if a camera reconnects with
     * a backlog.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('hik-webhook', function (Request $request) {
            $cameraId = (int) $request->route('camera');

            return Limit::perSecond(60)->by('hik:'.$cameraId);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

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
