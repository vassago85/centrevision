<?php

use App\Models\Organization;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\User;

it('renders every owner tab', function (string $route) {
    $owner = Organization::factory()->owner()->create();
    Site::factory()->for_($owner)->create();

    $this->actingAs(User::factory()->ownerAdmin($owner)->create())
        ->get(route($route))
        ->assertOk();
})->with(['overview', 'cameras', 'security', 'shops', 'reports', 'billing', 'settings']);

it('renders the platform tabs', function (string $route) {
    $this->actingAs(User::factory()->platformAdmin()->create())
        ->get(route($route))
        ->assertOk();
})->with(['platform.overview', 'platform.owners', 'platform.partners']);

it('shows owners the full tab bar', function () {
    $owner = Organization::factory()->owner()->create();
    Site::factory()->for_($owner)->create();

    $this->actingAs(User::factory()->ownerAdmin($owner)->create())
        ->get(route('overview'))
        ->assertSee('Sites')
        ->assertSee('Cameras')
        ->assertSee('Security')
        ->assertSee('Watchlist')
        ->assertSee('Sub-accounts')
        ->assertSee('Billing')
        ->assertSee('centre')
        ->assertSee('vision');
});

it('hides owner-only tabs from shop users', function () {
    $site = Site::factory()->create();
    $shop = Organization::factory()->shop($site)->create();
    ShopSubscription::factory()->for($shop, 'organization')->create();

    $this->actingAs(User::factory()->shopAdmin($shop)->create())
        ->get(route('overview'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Reports')
        ->assertDontSee('>Cameras<', false)
        ->assertDontSee('>Security<', false)
        ->assertDontSee('>Watchlist<', false)
        ->assertDontSee('>Sub-accounts<', false)
        ->assertDontSee('>Billing<', false);
});

it('sends guests to the login page', function () {
    $this->get(route('overview'))->assertRedirect(route('login'));
});

it('shows the marketing landing to guests', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('See every visit.', escape: false)
        ->assertSee('Month-to-month', escape: false)
        ->assertSee('Cancel any month');
});

it('points the root url at the first tab a user can see', function () {
    $owner = Organization::factory()->owner()->create();
    Site::factory()->for_($owner)->create();

    $this->actingAs(User::factory()->ownerAdmin($owner)->create())
        ->get('/')
        ->assertRedirect(route('overview'));

    $this->actingAs(User::factory()->platformAdmin()->create())
        ->get('/')
        ->assertRedirect(route('platform.overview'));
});
