<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

it('lets a platform admin visit the Settings and Approvals pages', function () {
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get(route('platform.settings'))->assertOk();
    $this->get(route('platform.approvals'))->assertOk();
});

it('refuses a plain owner admin', function () {
    // The owner needs at least one site or EnsureTenantContext redirects
    // them to Sites before the role middleware even runs, and the test
    // sees a 302 instead of the 403 that proves the role guard works.
    $owner = Organization::factory()->owner()->create();
    Site::factory()->for_($owner)->create();

    $this->actingAs(User::factory()->ownerAdmin($owner)->create());

    $this->get(route('platform.settings'))->assertForbidden();
    $this->get(route('platform.approvals'))->assertForbidden();
});

it('deep-links the active tab into the URL so support can share it', function () {
    Livewire::actingAs(User::factory()->platformAdmin()->create());

    Livewire::test('pages::platform.settings')
        ->assertSet('tab', 'mail')
        ->call('setTab', 'paystack')
        ->assertSet('tab', 'paystack');
});

it('clamps a tampered tab value to a real tab rather than blowing up', function () {
    Livewire::actingAs(User::factory()->platformAdmin()->create());

    Livewire::test('pages::platform.settings')
        ->call('setTab', 'not-a-real-tab')
        ->assertSet('tab', 'mail');
});
