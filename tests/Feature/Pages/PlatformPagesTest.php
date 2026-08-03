<?php

use App\Enums\BaseTier;
use App\Enums\InvoiceStatus;
use App\Enums\PayoutStatus;
use App\Models\Camera;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PartnerPayout;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    // Pin the clock to the start of a month so factory-built sites are
    // treated as full-period by the metered billing calculator.
    Date::setTestNow('2026-06-01 00:00:00');

    $this->partner = Partner::factory()->create(['name' => 'Northgate Installs', 'commission_rate' => 0.20]);

    $this->owner = Organization::factory()->owner()->referredBy($this->partner)->create(['name' => 'Owner A']);
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    SiteSubscription::factory()->for($this->site)->create();
    Camera::factory()->count(3)->for($this->site)->create();

    ShopSubscription::factory()
        ->for(Organization::factory()->shop($this->site), 'organization')
        ->create();

    $this->other = Organization::factory()->owner()->create(['name' => 'Owner B']);
    $otherSite = Site::factory()->for_($this->other)->create();
    Camera::factory()->count(6)->for($otherSite)->create();
    SiteSubscription::factory()->for($otherSite)->tier(BaseTier::Standard)->pastDue()->create();

    $this->admin = actingAsTenant(User::factory()->platformAdmin()->create());
});

it('shows revenue across every tenant', function () {
    Livewire::test('pages::platform.overview')
        // Owner A: R1,800 base + R54 variable + R120 platform shop share.
        // Owner B: R3,200 base.
        ->assertSee('R5,174.00');
});

it('lists invoices that are still unpaid', function () {
    Invoice::factory()->for_($this->owner)->create([
        'amount' => 1800.00,
        'status' => InvoiceStatus::Pending,
    ]);

    Invoice::factory()->for_($this->other)->paid()->create(['amount' => 3200.00]);

    Livewire::test('pages::platform.overview')
        ->assertSee('Owner A')
        ->assertSee('R1,800.00')
        ->assertDontSee('R3,200.00');
});

it('ranks owners by what they are worth to the platform', function () {
    Livewire::test('pages::platform.owners')
        ->assertSee('Owner A')
        ->assertSee('Owner B')
        ->assertSee('Northgate Installs')
        ->assertSeeInOrder(['Owner B', 'Owner A']);
});

it('filters owners down to the lapsed ones', function () {
    Livewire::test('pages::platform.owners')
        ->set('lapsedOnly', true)
        ->assertSee('Owner B')
        ->assertDontSee('Owner A');
});

it('searches owners by name', function () {
    Livewire::test('pages::platform.owners')
        ->set('search', 'owner a')
        ->assertSee('Owner A')
        ->assertDontSee('Owner B');
});

it('shows partner commission and lets an admin settle a payout', function () {
    $payout = PartnerPayout::factory()->for($this->partner)->create([
        'commission_amount' => 1000.00,
        'status' => PayoutStatus::Pending,
    ]);

    Livewire::test('pages::platform.partners')
        ->assertSee('Northgate Installs')
        ->assertSee('R1,000.00')
        ->call('markPaid', $payout->id);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and($payout->fresh()->paid_at)->not->toBeNull();
});

it('recalculates last month on demand', function () {
    Date::setTestNow('2026-08-10 09:00:00');

    Invoice::factory()
        ->for_($this->owner)
        ->paid()
        ->period(Date::parse('2026-07-01'), Date::parse('2026-07-31'))
        ->amount(5000.00)
        ->create();

    Livewire::test('pages::platform.partners')->call('recalculate');

    expect((float) PartnerPayout::sole()->commission_amount)->toBe(1000.00);
});

it('keeps owner admins out of the platform section', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $this->get(route('platform.overview'))->assertForbidden();
    $this->get(route('platform.owners'))->assertForbidden();
    $this->get(route('platform.partners'))->assertForbidden();
});
