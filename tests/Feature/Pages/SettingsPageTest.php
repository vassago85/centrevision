<?php

use App\Enums\PlateTagType;
use App\Models\Organization;
use App\Models\PlateTag;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);

    $this->user = actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());
});

it('loads the current settings, falling back to the application defaults', function () {
    Livewire::test('pages::settings')
        ->assertSet('name', 'Mall A')
        ->assertSet('dwellAlertHours', config('trafficflow.dwell_alert_hours'))
        ->assertSet('retentionDays', config('trafficflow.retention_days'))
        ->assertSet('recurringWindowDays', config('trafficflow.recurring_window_days'));
});

it('saves thresholds against the site', function () {
    Livewire::test('pages::settings')
        ->set('name', 'Mall A Renamed')
        ->set('dwellAlertHours', 6)
        ->set('orphanAfterHours', 8)
        ->set('retentionDays', 90)
        ->set('recurringWindowDays', 14)
        ->call('save')
        ->assertHasNoErrors();

    $site = $this->site->fresh();

    expect($site->name)->toBe('Mall A Renamed')
        ->and($site->dwellAlertHours())->toBe(6)
        ->and($site->orphanAfterHours())->toBe(8)
        ->and($site->retentionDays())->toBe(90);
});

it('holds retention inside the legally sensible range', function (int $days, bool $valid) {
    $test = Livewire::test('pages::settings')
        ->set('retentionDays', $days)
        ->call('save');

    $valid ? $test->assertHasNoErrors() : $test->assertHasErrors('retentionDays');
})->with([
    [10, false],
    [30, true],
    [1095, true],
    [2000, false],
]);

it('only offers thresholds the Security page can actually use', function () {
    Livewire::test('pages::settings')
        ->set('dwellAlertHours', 5)
        ->call('save')
        ->assertHasErrors('dwellAlertHours');
});

it('switching sites loads that site settings', function () {
    $second = Site::factory()->for_($this->owner)->create([
        'name' => 'Mall B',
        'settings' => ['retention_days' => 120],
    ]);

    Livewire::test('pages::settings')
        ->assertSet('name', 'Mall A')
        ->set('siteId', $second->id)
        ->assertSet('name', 'Mall B')
        ->assertSet('retentionDays', 120);
});

it('will not load a site belonging to another owner', function () {
    $foreign = Site::factory()->create(['name' => 'Not Yours']);

    Livewire::test('pages::settings')
        ->set('siteId', $foreign->id)
        ->assertSet('name', 'Mall A')
        ->call('save')
        ->assertNotFound();
});

it('saves the platform revenue share on the organization', function () {
    Livewire::test('pages::settings')
        ->set('platformShopRevenueShare', 0.25)
        ->call('saveRevenueShare')
        ->assertHasNoErrors();

    expect((float) $this->owner->fresh()->setting('platform_shop_revenue_share'))->toBe(0.25);
});

it('clears staff tags for the current site only', function () {
    $other = Site::factory()->for_($this->owner)->create();

    foreach ([$this->site, $other] as $site) {
        PlateTag::create([
            'site_id' => $site->id,
            'plate_number' => 'STAFF001',
            'tag' => PlateTagType::RecurringPattern,
            'tagged_at' => now(),
        ]);
    }

    Livewire::test('pages::settings')
        ->set('siteId', $this->site->id)
        ->call('clearRecurringTags');

    // Clearing this site's recurring tags must leave the other site untouched.
    expect(PlateTag::where('site_id', $this->site->id)->count())->toBe(0)
        ->and(PlateTag::where('site_id', $other->id)->count())->toBe(1);
});

it('lists the organization team', function () {
    $colleague = User::factory()->ownerAdmin($this->owner)->create(['name' => 'Sipho Ndlovu']);
    $outsider = User::factory()->create(['name' => 'Someone Else']);

    Livewire::test('pages::settings')
        ->assertSee($colleague->name)
        ->assertDontSee($outsider->name);
});
