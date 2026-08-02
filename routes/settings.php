<?php

use Illuminate\Support\Facades\Route;

/*
 * Personal account settings. These live under /account so that /settings can
 * belong to the TrafficFlow site settings tab in the main nav.
 */

Route::middleware(['auth'])->group(function () {
    Route::redirect('account', 'account/profile');

    Route::livewire('account/profile', 'pages::account.profile')->name('account.profile');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('account/appearance', 'pages::account.appearance')->name('account.appearance');

    Route::livewire('account/security', 'pages::account.security')
        ->middleware(['password.confirm'])
        ->name('account.security');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('account.security'),
        'manage' => route('account.security'),
    ]);
})->name('well-known.passkeys');
