<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    Date::setTestNow('2026-09-11 14:00:00');

    $this->admin = actingAsTenant(User::factory()->platformAdmin()->create());
});

it('shows Never for a tenant whose users have never signed in', function () {
    $owner = Organization::factory()->owner()->create(['name' => 'Silent Owner']);
    Site::factory()->for_($owner)->create();
    SiteSubscription::factory()->for(Site::query()->latest('id')->first())->create();

    User::factory()->ownerAdmin($owner)->create(['last_login_at' => null]);

    Livewire::test('pages::platform.owners')
        ->assertSee('Silent Owner')
        ->assertSee('Never');
});

it('shows a positive-toned pill for a recent login', function () {
    $owner = Organization::factory()->owner()->create(['name' => 'Blueberry Square']);
    Site::factory()->for_($owner)->create();
    SiteSubscription::factory()->for(Site::query()->latest('id')->first())->create();

    User::factory()->ownerAdmin($owner)->create([
        'last_login_at' => Date::now()->subMinutes(30),
    ]);

    $component = Livewire::test('pages::platform.owners');

    // 30 minutes ago should render inside a positive-soft pill (green).
    expect($component->html())
        ->toContain('Blueberry Square')
        ->toContain('bg-positive-soft');
});

it('uses the freshest login across every user in the organization', function () {
    $owner = Organization::factory()->owner()->create(['name' => 'Multi User Owner']);
    Site::factory()->for_($owner)->create();
    SiteSubscription::factory()->for(Site::query()->latest('id')->first())->create();

    User::factory()->ownerAdmin($owner)->create([
        'last_login_at' => Date::now()->subDays(10),
    ]);
    User::factory()->securityOperator($owner)->create([
        'last_login_at' => Date::now()->subMinutes(5),
    ]);

    $metrics = app(\App\Support\Platform\PlatformMetrics::class);

    $summary = $metrics->ownerSummaries()->firstWhere(
        fn ($owner) => $owner->organization->name === 'Multi User Owner'
    );

    expect($summary)->not->toBeNull()
        ->and($summary->lastLoginAt?->diffInMinutes(now(), absolute: true))->toBeLessThan(6);
});
