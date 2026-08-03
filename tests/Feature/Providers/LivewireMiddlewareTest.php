<?php

use App\Http\Middleware\EnsureSubscriptionActive;
use App\Http\Middleware\EnsureTenantContext;
use Livewire\Livewire;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

/**
 * Livewire only re-runs a hardcoded whitelist of middleware for /livewire/update
 * requests. Anything not in that whitelist is silently dropped, so the tenant
 * singleton would never be populated for wire:click / wire:model interactions —
 * which caused the Sites page to blank out to "No sites configured yet" the
 * moment a user clicked Focus. AppServiceProvider adds ours to the whitelist;
 * this test locks that in so a future refactor cannot silently drop it again.
 */
it('registers our custom middleware as Livewire persistent middleware', function () {
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)
        ->toContain(EnsureTenantContext::class)
        ->toContain(EnsureSubscriptionActive::class)
        ->toContain(RoleMiddleware::class)
        ->toContain(PermissionMiddleware::class);
});
