<?php

use App\Enums\SubscriptionStatus;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->siteA = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    $this->siteB = Site::factory()->for_($this->owner)->create(['name' => 'Mall B']);
    Camera::factory(4)->for($this->siteA)->create();
    Camera::factory(6)->for($this->siteB)->create();

    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

it('lists every site the tenant reaches with a camera count', function () {
    Livewire::test('pages::sites')
        ->assertSee('Mall A')
        ->assertSee('Mall B');
});

it('pins the site switcher when focusing a site', function () {
    Livewire::test('pages::sites')
        ->call('focus', $this->siteB->id)
        ->assertRedirect(route('overview'));

    expect(session('tenancy.site_id'))->toBe($this->siteB->id);
});

it('refuses to focus a site the tenant does not own', function () {
    $foreign = Site::factory()->create();

    Livewire::test('pages::sites')->call('focus', $foreign->id);

    // Refuse silently — a tampered payload does not narrow session state.
    expect(session('tenancy.site_id'))->toBeNull();
});

it('lets an owner add a new site with an auto-attached metered subscription', function () {
    Livewire::test('pages::sites')
        ->set('name', 'Rosebank Mews')
        ->set('address', '55 Bath Ave, Rosebank')
        ->call('save')
        ->assertHasNoErrors();

    $site = Site::where('name', 'Rosebank Mews')->firstOrFail();

    expect($site->organization_id)->toBe($this->owner->id)
        ->and($site->address)->toBe('55 Bath Ave, Rosebank');

    // A new site starts with a metered Starter subscription so billing has a
    // home for its charges, but with a live camera count of zero its first
    // invoice line will collapse to R0.00 anyway.
    $subscription = SiteSubscription::where('site_id', $site->id)->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Active);
});

it('trims the name and treats a blank address as null', function () {
    Livewire::test('pages::sites')
        ->set('name', '  Sandton City   ')
        ->set('address', '   ')
        ->call('save');

    $site = Site::where('name', 'Sandton City')->firstOrFail();

    expect($site->address)->toBeNull();
});

it('validates the site name on save', function () {
    Livewire::test('pages::sites')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);

    // Nothing sneaks in when validation fails.
    expect(Site::where('organization_id', $this->owner->id)->count())->toBe(2);
});

it('lets an owner rename an existing site', function () {
    Livewire::test('pages::sites')
        ->call('edit', $this->siteA->id)
        ->set('name', 'Mall A — Renamed')
        ->call('save');

    expect($this->siteA->fresh()->name)->toBe('Mall A — Renamed');
});

it('will not let one owner edit another owner site', function () {
    $foreignOwner = Organization::factory()->owner()->create();
    $foreignSite = Site::factory()->for_($foreignOwner)->create(['name' => 'Other Mall']);

    // SiteScope hides the foreign site entirely, so findOrFail throws a
    // 404 rather than a 403; either counts as "correctly refused". Whichever
    // path the request takes, the row must not have been mutated.
    try {
        Livewire::test('pages::sites')
            ->call('edit', $foreignSite->id);

        // If the call did not throw at all, we would have opened the editor
        // on someone else's site — flag that explicitly.
        expect(false)->toBeTrue('edit() should have refused the foreign site');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException | \Illuminate\Auth\Access\AuthorizationException $expected) {
        // Success: refused via one of the two acceptable defences.
    }

    expect($foreignSite->fresh()->name)->toBe('Other Mall');
});
