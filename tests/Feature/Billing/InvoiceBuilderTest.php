<?php

use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Jobs\GenerateMonthlyInvoices;
use App\Models\Camera;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Support\Billing\InvoiceBuilder;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    // Sites created after a billing period starts are prorated by day count,
    // so we pin the clock to the start of the period before creating anything.
    // This mirrors the realistic case where a site has already been running
    // for the full month by the time its invoice is generated.
    Date::setTestNow('2026-06-15');

    $this->owner = Organization::factory()->owner()->create(['name' => 'Owner A']);
    $this->siteA = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    $this->siteB = Site::factory()->for_($this->owner)->create(['name' => 'Mall B']);

    Camera::factory()->count(3)->for($this->siteA)->create();
    Camera::factory()->count(6)->for($this->siteB)->create();

    $this->period = Date::parse('2026-07-01');
    $this->builder = app(InvoiceBuilder::class);
});

it('issues one consolidated invoice with a line per site', function () {
    $invoice = $this->builder->forOwner($this->owner, $this->period);

    expect((float) $invoice->amount)->toBe(5000.00)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->period_start->toDateString())->toBe('2026-07-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-07-31');

    $bySite = $invoice->lines->groupBy('site_id');

    expect($bySite)->toHaveCount(2)
        ->and($bySite[$this->siteA->id]->firstWhere('kind', InvoiceLineKind::BaseFee)->amount)->toEqual(1800.00)
        ->and($bySite[$this->siteB->id]->firstWhere('kind', InvoiceLineKind::BaseFee)->amount)->toEqual(3200.00);
});

it('shows the variable fee working on the line', function () {
    ShopSubscription::factory()
        ->for(Organization::factory()->shop($this->siteA), 'organization')
        ->create();

    $line = $this->builder->forOwner($this->owner, $this->period)
        ->lines
        ->firstWhere('kind', InvoiceLineKind::VariableFee);

    expect($line->label)->toContain('3 cameras × 1 shops')
        ->and((float) $line->amount)->toBe(54.00)
        ->and($line->meta['paying_shops'])->toBe(1);
});

it('adds a camera surcharge line only when the site is over the ceiling', function () {
    Camera::factory()->count(12)->for($this->siteB)->create();

    $lines = $this->builder->forOwner($this->owner, $this->period)->lines;

    expect($lines->where('kind', InvoiceLineKind::CameraSurcharge))->toHaveCount(1)
        ->and($lines->firstWhere('kind', InvoiceLineKind::CameraSurcharge)->site_id)->toBe($this->siteB->id);
});

it('does not bill the same period twice', function () {
    $first = $this->builder->forOwner($this->owner, $this->period);
    $second = $this->builder->forOwner($this->owner, Date::parse('2026-07-19'));

    expect($second->id)->toBe($first->id)
        ->and(Invoice::count())->toBe(1);
});

it('bills a shop its flat monthly fee', function () {
    $shop = Organization::factory()->shop($this->siteA)->create();
    ShopSubscription::factory()->for($shop, 'organization')->amount(450.00)->create();

    $invoice = $this->builder->forShop($shop, $this->period);

    expect((float) $invoice->amount)->toBe(450.00)
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->kind)->toBe(InvoiceLineKind::ShopSubscription)
        ->and($invoice->lines->first()->site_id)->toBe($this->siteA->id);
});

it('bills owners and paying shops for the period', function () {
    ShopSubscription::factory()
        ->for(Organization::factory()->shop($this->siteA), 'organization')
        ->create();

    // A trialing shop is not yet revenue, so it gets no invoice.
    ShopSubscription::factory()
        ->for(Organization::factory()->shop($this->siteB), 'organization')
        ->trialing()
        ->create();

    $invoices = $this->builder->generateForPeriod($this->period);

    expect($invoices)->toHaveCount(2)
        ->and(Invoice::where('billable_id', $this->owner->id)->exists())->toBeTrue();
});

it('gives every invoice a unique sequential number', function () {
    ShopSubscription::factory()
        ->for(Organization::factory()->shop($this->siteA), 'organization')
        ->create();

    $numbers = $this->builder->generateForPeriod($this->period)->pluck('number');

    expect($numbers->unique())->toHaveCount(2)
        ->and($numbers->first())->toStartWith('TF-202607-');
});

it('bills the month just finished when the job runs unattended', function () {
    Date::setTestNow('2026-08-01 04:00:00');

    (new GenerateMonthlyInvoices)->handle($this->builder);

    expect(Invoice::first()->period_start->toDateString())->toBe('2026-07-01');
});

it('is safe to run the monthly job twice', function () {
    (new GenerateMonthlyInvoices('2026-07-01'))->handle($this->builder);
    (new GenerateMonthlyInvoices('2026-07-01'))->handle($this->builder);

    expect(Invoice::count())->toBe(1);
});
