<?php

use App\Models\Camera;
use App\Models\Organization;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();
    $this->shop = Organization::factory()->shop($this->site)->create();

    Camera::factory()->for($this->site)->create();
});

it('sends a user with no site to a dead end rather than an unscoped app', function () {
    $orphan = User::factory()->ownerAdmin(Organization::factory()->owner()->create())->create();

    $this->actingAs($orphan)->get(route('overview'))->assertForbidden();
});

it('keeps shop users out of owner only pages', function (string $route) {
    ShopSubscription::factory()->for($this->shop, 'organization')->create();

    $user = User::factory()->shopAdmin($this->shop)->create();

    $this->actingAs($user)->get(route($route))->assertForbidden();
})->with(['cameras', 'shops', 'billing', 'settings', 'security']);

it('keeps owner admins out of platform pages', function () {
    $user = User::factory()->ownerAdmin($this->owner)->create();

    $this->actingAs($user)->get(route('platform.overview'))->assertForbidden();
});

it('lets an owner admin reach their own pages', function (string $route) {
    $user = User::factory()->ownerAdmin($this->owner)->create();

    $this->actingAs($user)->get(route($route))->assertOk();
})->with(['overview', 'cameras', 'security', 'shops', 'reports', 'billing', 'settings']);

it('redirects a past due shop to the paywall', function () {
    ShopSubscription::factory()->for($this->shop, 'organization')->pastDue()->create();

    $user = User::factory()->shopAdmin($this->shop)->create();

    $this->actingAs($user)->get(route('overview'))->assertRedirect(route('paywall'));
});

it('keeps an owner working while only one of two sites has lapsed', function () {
    $second = Site::factory()->for_($this->owner)->create();

    SiteSubscription::factory()->for($this->site)->create();
    SiteSubscription::factory()->for($second)->pastDue()->create();

    $user = User::factory()->ownerAdmin($this->owner)->create();

    $this->actingAs($user)->get(route('overview'))
        ->assertOk()
        ->assertSee('Payment is outstanding');
});

it('locks out an owner once every site has lapsed', function () {
    SiteSubscription::factory()->for($this->site)->canceled()->create();

    $user = User::factory()->ownerAdmin($this->owner)->create();

    $this->actingAs($user)->get(route('overview'))->assertRedirect(route('paywall'));
});

it('leaves a trialing owner alone', function () {
    SiteSubscription::factory()->for($this->site)->create();

    $user = User::factory()->ownerAdmin($this->owner)->create();

    $this->actingAs($user)->get(route('overview'))->assertOk();
});

it('lets a paywalled user still reach the paywall and their account', function (string $route) {
    ShopSubscription::factory()->for($this->shop, 'organization')->canceled()->create();

    $user = User::factory()->shopAdmin($this->shop)->create();

    $this->actingAs($user)->get(route($route))->assertOk();
})->with(['paywall', 'account.profile']);
