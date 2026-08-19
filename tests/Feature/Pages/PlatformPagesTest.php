<?php

use App\Enums\InvoiceStatus;
use App\Enums\PayoutStatus;
use App\Models\Camera;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PartnerPayout;
use App\Models\Scopes\SiteScope;
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
    SiteSubscription::factory()->for($otherSite)->pastDue()->create();

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

it('lets a platform admin flag an owner as free', function () {
    Livewire::test('pages::platform.owners')
        ->call('openBilling', $this->owner->id)
        ->assertSet('showBilling', true)
        ->assertSet('editingBillingId', $this->owner->id)
        ->assertSet('billingFree', false)
        ->set('billingFree', true)
        ->call('saveBilling')
        ->assertSet('showBilling', false)
        ->assertSet('editingBillingId', null);

    expect($this->owner->fresh()->isOnFreeBillingPlan())->toBeTrue();
});

it('never binds the modal open state to the owner id property', function () {
    // Regression: previously `<flux:modal wire:model.self="editingBillingId">`
    // let Flux coerce `true` back into the int property on close, causing
    // saveBilling to target org id 1 instead of whichever owner was clicked.
    // The modal must be driven by the boolean flag, and the id must stay put
    // through the whole open → edit → save flow.
    expect(file_get_contents(resource_path('views/pages/platform/owners.blade.php')))
        ->toContain('wire:model.self="showBilling"')
        ->not->toContain('wire:model.self="editingBillingId"');
});

it('lets a platform admin save a per-owner base fee override', function () {
    Livewire::test('pages::platform.owners')
        ->call('openBilling', $this->owner->id)
        ->set('billingBaseFeeOverride', '2500')
        ->set('billingNotes', '6-month pilot')
        ->call('saveBilling')
        ->assertHasNoErrors();

    $fresh = $this->owner->fresh();

    expect($fresh->setting('billing.base_fee_override'))->toBe(2500.0)
        ->and($fresh->setting('billing.notes'))->toBe('6-month pilot')
        ->and($fresh->hasCustomBillingPlan())->toBeTrue();
});

it('badges free and custom plans in the owners table', function () {
    $this->owner->update(['settings' => ['billing' => ['free' => true]]]);
    $this->other->update(['settings' => ['billing' => ['base_fee_override' => 2500.00]]]);

    Livewire::test('pages::platform.owners')
        ->assertSee('Free')
        ->assertSee('Custom');
});

it('stores a one-third partner share at enough precision to split R1500 into R500', function () {
    Livewire::test('pages::platform.partners')
        ->call('openPartner')
        ->set('partnerName', 'Stephan van der Merwe')
        ->set('partnerEmail', 'stephan@zentechiss.co.za')
        ->set('partnerCommissionPercent', '33.3333')
        ->call('savePartner')
        ->assertHasNoErrors();

    $partner = Partner::where('email', 'stephan@zentechiss.co.za')->firstOrFail();

    expect((float) $partner->commission_rate)->toBe(0.333333)
        ->and($partner->shareOf(1500.00))->toBe(500.00)
        ->and($partner->shareOf(1860.00))->toBe(620.00);
});

it('adds a new partner from the platform partners page', function () {
    Livewire::test('pages::platform.partners')
        ->call('openPartner')
        ->assertSet('showPartner', true)
        ->assertSet('editingPartnerId', null)
        ->set('partnerName', 'Southgate Installs')
        ->set('partnerEmail', 'billing@southgate.co.za')
        ->set('partnerCommissionPercent', '15')
        ->call('savePartner')
        ->assertHasNoErrors()
        ->assertSet('showPartner', false)
        ->assertSet('editingPartnerId', null);

    $partner = Partner::where('email', 'billing@southgate.co.za')->firstOrFail();

    expect($partner->name)->toBe('Southgate Installs')
        ->and((float) $partner->commission_rate)->toBe(0.15);
});

it('never binds the partner modal open state to the partner id property', function () {
    // Regression: same class of bug as the owners billing modal — Flux would
    // coerce `true` back into `editingPartnerId` on close, so a subsequent
    // save could accidentally edit whatever partner sat at that key.
    expect(file_get_contents(resource_path('views/pages/platform/partners.blade.php')))
        ->toContain('wire:model.self="showPartner"')
        ->not->toContain('wire:model.self="editingPartnerId"');
});

it('rejects a duplicate partner email', function () {
    Livewire::test('pages::platform.partners')
        ->call('openPartner')
        ->set('partnerName', 'Someone Else')
        ->set('partnerEmail', $this->partner->email)
        ->set('partnerCommissionPercent', '10')
        ->call('savePartner')
        ->assertHasErrors(['partnerEmail']);
});

it('edits an existing partner in place', function () {
    Livewire::test('pages::platform.partners')
        ->call('openPartner', $this->partner->id)
        ->assertSet('partnerName', $this->partner->name)
        ->set('partnerCommissionPercent', '25')
        ->call('savePartner')
        ->assertHasNoErrors();

    expect((float) $this->partner->fresh()->commission_rate)->toBe(0.25);
});

it('deletes a partner and clears attributed owners without touching them', function () {
    // Give the partner some payout history so we can assert the cascade
    // fires — otherwise a naive delete looks the same as an archive.
    PartnerPayout::factory()
        ->for($this->partner)
        ->create(['status' => PayoutStatus::Paid, 'commission_amount' => 1234.00]);

    $ownerId = $this->owner->id;
    $partnerId = $this->partner->id;

    Livewire::test('pages::platform.partners')
        ->call('openPartner', $partnerId)
        ->call('deletePartner')
        ->assertSet('showPartner', false)
        ->assertSet('editingPartnerId', null);

    expect(Partner::find($partnerId))->toBeNull()
        ->and(PartnerPayout::where('partner_id', $partnerId)->count())->toBe(0)
        // Owner survives — only the referrer pointer is cleared.
        ->and(Organization::find($ownerId))->not->toBeNull()
        ->and(Organization::find($ownerId)->referred_by_partner_id)->toBeNull();
});

it('surfaces the delete impact for the confirm dialog', function () {
    // Two payouts in different months so we don't trip the
    // (partner_id, period_start, period_end) unique constraint.
    PartnerPayout::factory()->for($this->partner)->create([
        'status' => PayoutStatus::Paid,
        'commission_amount' => 500.00,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);
    PartnerPayout::factory()->for($this->partner)->create([
        'status' => PayoutStatus::Pending,
        'commission_amount' => 250.00,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ]);

    $component = Livewire::test('pages::platform.partners')
        ->call('openPartner', $this->partner->id);

    $impact = $component->instance()->deleteImpact;

    expect($impact['owners'])->toBe(1)
        ->and($impact['payouts'])->toBe(2)
        ->and($impact['paid_total'])->toBe(500.0)
        ->and($impact['pending_total'])->toBe(250.0);
});

it('assigns a partner to an owner from the edit-billing modal', function () {
    Livewire::test('pages::platform.owners')
        ->call('openBilling', $this->other->id)
        ->assertSet('billingPartnerId', '')
        ->set('billingPartnerId', (string) $this->partner->id)
        ->call('saveBilling')
        ->assertHasNoErrors();

    expect($this->other->fresh()->referred_by_partner_id)->toBe($this->partner->id);
});

it('lets a platform admin save a per-site agreement', function () {
    $sitePartner = Partner::factory()->create(['name' => 'Stephan Installs']);

    Livewire::test('pages::platform.owners')
        ->call('openBilling', $this->owner->id)
        ->set('billingSites.0.base_fee', '1500')
        ->set('billingSites.0.partner_id', (string) $sitePartner->id)
        ->call('saveBilling')
        ->assertHasNoErrors();

    $subscription = SiteSubscription::query()
        ->withoutGlobalScope(SiteScope::class)
        ->where('site_id', $this->site->id)
        ->firstOrFail();

    expect((float) $subscription->base_fee)->toBe(1500.00)
        ->and($subscription->partner_id)->toBe($sitePartner->id);
});

it('clears an owner-partner attribution when the dropdown is set to empty', function () {
    // Owner A already has $this->partner attached in the beforeEach.
    Livewire::test('pages::platform.owners')
        ->call('openBilling', $this->owner->id)
        ->assertSet('billingPartnerId', (string) $this->partner->id)
        ->set('billingPartnerId', '')
        ->call('saveBilling')
        ->assertHasNoErrors();

    expect($this->owner->fresh()->referred_by_partner_id)->toBeNull();
});

it('keeps owner admins out of the platform section', function () {
    actingAsTenant(User::factory()->ownerAdmin($this->owner)->create());

    $this->get(route('platform.overview'))->assertForbidden();
    $this->get(route('platform.owners'))->assertForbidden();
    $this->get(route('platform.partners'))->assertForbidden();
});
