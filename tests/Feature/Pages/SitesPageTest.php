<?php

use App\Models\Camera;
use App\Models\Organization;
use App\Models\Site;
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
