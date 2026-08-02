<?php

use App\Http\Controllers\Billing\PaymentCallbackController;
use App\Http\Controllers\Billing\PaystackWebhookController;
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

        // Owner-only. Shops get aggregates for their site and nothing else.
        Route::middleware('role:owner_admin')->group(function () {
            Route::livewire('sites', 'pages::sites')->name('sites');
            Route::livewire('cameras', 'pages::cameras')->name('cameras');
            Route::livewire('security', 'pages::security')->name('security');
            Route::livewire('watchlist', 'pages::watchlist')->name('watchlist');
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
