<?php

use App\Enums\VisitStatus;
use App\Models\Organization;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);

    foreach ([1, 2, 2, 3] as $daysAgo) {
        Visit::factory()->for($this->site)->create([
            'entered_at' => Date::now()->subDays($daysAgo)->setTime(14, 0),
            'exited_at' => Date::now()->subDays($daysAgo)->setTime(14, 30),
            'dwell_minutes' => 30,
            'status' => VisitStatus::Closed,
        ]);
    }
});

it('summarises the period for an owner', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::reports')
        ->assertSet('rangeKey', '30d')
        ->assertSee('Total visits')
        ->assertSee('Daily average')
        ->assertSee('Visits per day')
        ->assertSee('Daily breakdown');
});

it('is available to shops, since it is only aggregates', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    ShopSubscription::factory()->for($shop, 'organization')->create();

    actingAsTenant(User::factory()->shopAdmin($shop)->create());

    Livewire::test('pages::reports')
        ->assertSee('Mall A')
        ->assertSee('Total visits');
});

it('counts the busiest day correctly', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $busiest = Date::now()->subDays(2)->format('j M');

    Livewire::test('pages::reports')
        ->assertSee($busiest)
        ->assertSee('2 vehicles');
});

it('narrows to today when asked, dropping the older visits', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Visit::factory()->for($this->site)->create([
        'entered_at' => Date::now()->subHour(),
        'exited_at' => Date::now(),
        'dwell_minutes' => 60,
        'status' => VisitStatus::Closed,
    ]);

    Livewire::test('pages::reports')
        ->set('rangeKey', 'today')
        ->assertSee('All sites · today')
        ->assertSeeInOrder(['Total visits', '1']);
});

it('shows owner reporting sections including security and data quality', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    Livewire::test('pages::reports')
        ->assertSee('Unique vehicles')
        ->assertSee('Return rate')
        ->assertSee('Visitor behaviour')
        ->assertSee('Security')
        ->assertSee('Data quality')
        ->assertSee('Visit pairing quality');
});

it('hides security and data quality from shop accounts', function () {
    $shop = Organization::factory()->shop($this->site)->create();
    ShopSubscription::factory()->for($shop, 'organization')->create();

    actingAsTenant(User::factory()->shopAdmin($shop)->create());

    Livewire::test('pages::reports')
        ->assertSee('Total visits')
        ->assertSee('Visitor behaviour')
        ->assertDontSee('Watchlist hits')
        ->assertDontSee('Visit pairing quality')
        ->assertDontSee('Data quality');
});

it('hides occupancy until the selected site has parking capacity', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
    app(Tenancy::class)->setCurrentSiteId($this->site->id);

    Livewire::test('pages::reports')
        ->assertDontSee('Parking pressure');

    $this->site->update(['settings' => ['parking_capacity' => 200]]);

    Livewire::test('pages::reports')
        ->assertSee('Peak occupancy')
        ->assertSee('Parking pressure');
});
