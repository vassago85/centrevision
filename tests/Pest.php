<?php

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * Log in and populate the tenant context.
 *
 * EnsureTenantContext does this for real requests, but Livewire component
 * tests and direct Eloquent assertions never touch middleware, so without
 * this the SiteScope stays dormant and nothing is actually scoped.
 */
function actingAsTenant(User $user): User
{
    test()->actingAs($user);

    app(Tenancy::class)->setUser($user);

    return $user;
}
