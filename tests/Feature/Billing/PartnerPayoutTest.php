<?php

use App\Enums\PayoutStatus;
use App\Jobs\GeneratePartnerPayouts;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PartnerPayout;
use App\Models\Site;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->partner = Partner::factory()->create(['commission_rate' => 0.20]);
    $this->owner = Organization::factory()->owner()->referredBy($this->partner)->create();
    $this->site = Site::factory()->for_($this->owner)->create();

    $this->start = Date::parse('2026-07-01');
    $this->end = Date::parse('2026-07-31');
});

/**
 * A settled invoice for the July period.
 */
function paidInvoice(Organization $billable, float $amount): Invoice
{
    return Invoice::factory()
        ->for_($billable)
        ->paid()
        ->period(Date::parse('2026-07-01'), Date::parse('2026-07-31'))
        ->amount($amount)
        ->create();
}

it('commissions the partner on revenue actually received', function () {
    paidInvoice($this->owner, 5000.00);

    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    $payout = PartnerPayout::sole();

    expect((float) $payout->revenue_base)->toBe(5000.00)
        ->and((float) $payout->commission_amount)->toBe(1000.00)
        ->and($payout->status)->toBe(PayoutStatus::Pending);
});

it('counts the shops trading inside the referred owner sites', function () {
    $shop = Organization::factory()->shop($this->site)->create();

    paidInvoice($this->owner, 5000.00);
    paidInvoice($shop, 400.00);

    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    expect((float) PartnerPayout::sole()->revenue_base)->toBe(5400.00);
});

it('ignores invoices that were never paid', function () {
    paidInvoice($this->owner, 5000.00);
    Invoice::factory()->for_($this->owner)->period($this->start, $this->end)->amount(3000.00)->create();

    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    expect((float) PartnerPayout::sole()->revenue_base)->toBe(5000.00);
});

it('ignores revenue from another period', function () {
    paidInvoice($this->owner, 5000.00);

    Invoice::factory()
        ->for_($this->owner)
        ->paid()
        ->period(Date::parse('2026-06-01'), Date::parse('2026-06-30'))
        ->amount(4000.00)
        ->create();

    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    expect((float) PartnerPayout::sole()->revenue_base)->toBe(5000.00);
});

it('ignores owners the partner did not refer', function () {
    paidInvoice(Organization::factory()->owner()->create(), 9000.00);

    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    expect((float) PartnerPayout::sole()->revenue_base)->toBe(0.0);
});

it('picks up an invoice that settled after the first run', function () {
    paidInvoice($this->owner, 5000.00);
    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    paidInvoice($this->owner, 1000.00);
    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    expect(PartnerPayout::count())->toBe(1)
        ->and((float) PartnerPayout::sole()->commission_amount)->toBe(1200.00);
});

it('never rewrites a payout that has already been paid', function () {
    paidInvoice($this->owner, 5000.00);
    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    PartnerPayout::sole()->update(['status' => PayoutStatus::Paid, 'paid_at' => now()]);

    paidInvoice($this->owner, 9000.00);
    (new GeneratePartnerPayouts('2026-07-01'))->handle();

    expect((float) PartnerPayout::sole()->commission_amount)->toBe(1000.00);
});

it('bills the month just finished when run unattended', function () {
    Date::setTestNow('2026-08-08 04:30:00');
    paidInvoice($this->owner, 5000.00);

    (new GeneratePartnerPayouts)->handle();

    expect(PartnerPayout::sole()->period_start->toDateString())->toBe('2026-07-01');
});
