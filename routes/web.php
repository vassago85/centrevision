<?php

use App\Http\Controllers\Billing\PaymentCallbackController;
use App\Http\Controllers\Billing\PaystackWebhookController;
use App\Http\Controllers\HikvisionWebhookController;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;

// Signed-in tenants land straight on their dashboard; everyone else sees the
// marketing pitch and picks Log in or Sign up from there.
Route::get('/', function () {
    $user = auth()->user();

    if ($user !== null) {
        return redirect()->route(Navigation::homeRouteFor($user));
    }

    return view('marketing.landing');
})->name('home');

// The gateway calls this one, so it carries no session and is authenticated by
// its signature instead.
Route::post('webhooks/paystack', PaystackWebhookController::class)->name('webhooks.paystack');

// Hikvision cameras post plate events here over HTTPS. The camera holds the
// connection open until we answer, so the handler stages the payload and
// dispatches a queued job — everything database-facing runs on the worker.
// AuthenticateHikCamera accepts either HTTP Basic against the per-camera
// secret (older Hikvision firmware) or the same secret as a trailing path
// segment (newer firmware whose "Alarm Server" screen doesn't expose auth
// fields — see docs/hikvision-camera-setup.md).
Route::post('webhooks/hik/{camera}/{token?}', HikvisionWebhookController::class)
    ->where('camera', '[0-9]+')
    ->where('token', '[A-Za-z0-9_-]{8,128}')
    ->middleware(['auth.hik-camera', 'throttle:hik-webhook'])
    ->name('webhooks.hik');

// Guests arrive here from an owner's invitation email and register their shop.
Route::livewire('shop-invitations/{token}', 'pages::shop-invitation')->name('shop-invitations.show');

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    // Reachable while a subscription is lapsed, so a tenant can pay their way
    // back in rather than being locked out of the only page that would help.
    Route::livewire('paywall', 'pages::paywall')->name('paywall');
    Route::livewire('billing', 'pages::billing')->name('billing')->middleware('role:owner_admin');
    Route::get('billing/callback', PaymentCallbackController::class)->name('billing.callback');

    Route::middleware('subscribed')->group(function () {
        Route::livewire('overview', 'pages::overview')->name('overview');
        Route::livewire('reports', 'pages::reports')->name('reports');

        // Security operators are hired by the owner to watch plates all day
        // long, so they need the security tools even though they can't spend
        // the owner's money.
        Route::middleware('role:owner_admin|security_operator')->group(function () {
            Route::livewire('cameras', 'pages::cameras')->name('cameras');
            Route::livewire('security', 'pages::security')->name('security');
            Route::livewire('watchlist', 'pages::watchlist')->name('watchlist');
        });

        // Owner-only. Shops get aggregates for their site and nothing else,
        // and security operators are deliberately kept away from anything
        // that changes billing, sites or shops.
        Route::middleware('role:owner_admin')->group(function () {
            Route::livewire('sites', 'pages::sites')->name('sites');
            Route::livewire('shops', 'pages::shops')->name('shops');
            Route::livewire('settings', 'pages::settings')->name('settings');
        });
    });

    Route::prefix('platform')->name('platform.')->middleware('role:platform_admin')->group(function () {
        Route::livewire('/', 'pages::platform.overview')->name('overview');
        Route::livewire('owners', 'pages::platform.owners')->name('owners');
        Route::livewire('partners', 'pages::platform.partners')->name('partners');
    });
});

require __DIR__.'/settings.php';
