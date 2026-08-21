<?php

use App\Models\Camera;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use App\Support\Navigation;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();
    Camera::factory()->for($this->site)->entrance()->create();

    // Every gated tab lives behind the `subscribed` middleware, so a site
    // subscription must exist or the operator lands on the paywall instead
    // of the page under test.
    SiteSubscription::factory()->for($this->site)->create();

    $this->operator = User::factory()->securityOperator($this->owner)->create();
});

it('lets an operator hit the pages they need to do their job', function () {
    $this->actingAs($this->operator);

    foreach (['overview', 'cameras', 'activity', 'security', 'watchlist', 'reports'] as $route) {
        $this->get(route($route))->assertOk();
    }
});

it('blocks an operator from owner-only tabs', function () {
    $this->actingAs($this->operator);

    foreach (['sites', 'shops', 'settings', 'billing'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
});

it('does not offer sites, shops, billing or settings in the navigation', function () {
    $routes = collect(Navigation::for($this->operator))->pluck('route')->all();

    expect($routes)
        ->toContain('overview', 'cameras', 'activity', 'security', 'watchlist', 'reports')
        ->not->toContain('sites', 'shops', 'billing', 'settings');
});

it('lands an operator on the dashboard rather than the sites page', function () {
    expect(Navigation::homeRouteFor($this->operator))->toBe('overview');
});
