<?php

use App\Enums\BaseTier;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Support\Billing\BillingCalculator;

beforeEach(function () {
    // Sites created part-way through a billing period get prorated by day
    // count, which is not what most tests here are trying to prove. Pinning
    // time to the start of a fixed month means every factory-built site is
    // "already alive" when the current period starts, so the tests below can
    // reason about full-month figures without dragging today's date in.
    \Illuminate\Support\Facades\Date::setTestNow('2026-06-01 00:00:00');

    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create(['name' => 'Mall A']);
    $this->calculator = app(BillingCalculator::class);
});

/**
 * Attach $count paying shops to a site.
 */
function payingShops(Site $site, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        ShopSubscription::factory()
            ->for(Organization::factory()->shop($site), 'organization')
            ->create();
    }
}

it('picks the tier from the camera count', function (int $cameras, BaseTier $tier, float $baseFee) {
    Camera::factory()->count($cameras)->for($this->site)->create();

    $charge = $this->calculator->chargeForSite($this->site);

    expect($charge->tier)->toBe($tier)
        ->and($charge->baseFee)->toBe($baseFee);
})->with([
    [3, BaseTier::Starter, 1800.00],
    [4, BaseTier::Starter, 1800.00],
    [6, BaseTier::Standard, 3200.00],
    [12, BaseTier::Large, 5500.00],
    [18, BaseTier::Enterprise, 5500.00],
]);

it('charges per camera above the Large ceiling', function () {
    Camera::factory()->count(19)->for($this->site)->create();

    $charge = $this->calculator->chargeForSite($this->site);

    // Three cameras past sixteen, at R300 each.
    expect($charge->cameraSurcharge)->toBe(900.00);
});

it('does not charge a camera surcharge below Enterprise', function () {
    Camera::factory()->count(16)->for($this->site)->create();

    expect($this->calculator->chargeForSite($this->site)->cameraSurcharge)->toBe(0.0);
});

it('multiplies cameras by paying shops for the variable fee', function () {
    Camera::factory()->count(4)->for($this->site)->create();
    payingShops($this->site, 3);

    $charge = $this->calculator->chargeForSite($this->site);

    // 4 cameras x 3 shops x R18.
    expect($charge->variableFee)->toBe(216.00)
        ->and($charge->total())->toBe(2016.00);
});

it('clamps the variable fee to the subscription cap', function () {
    Camera::factory()->count(8)->for($this->site)->create();
    payingShops($this->site, 10);

    SiteSubscription::factory()
        ->for($this->site)
        ->tier(BaseTier::Standard)
        ->cappedAt(500.00)
        ->create();

    $charge = $this->calculator->chargeForSite($this->site);

    expect($charge->uncappedVariableFee)->toBe(1440.00)
        ->and($charge->variableFee)->toBe(500.00)
        ->and($charge->wasCapped())->toBeTrue();
});

it('ignores shops that are not paying', function () {
    Camera::factory()->count(4)->for($this->site)->create();

    ShopSubscription::factory()
        ->for(Organization::factory()->shop($this->site), 'organization')
        ->trialing()
        ->create();

    ShopSubscription::factory()
        ->for(Organization::factory()->shop($this->site), 'organization')
        ->pastDue()
        ->create();

    $charge = $this->calculator->chargeForSite($this->site);

    expect($charge->payingShopCount)->toBe(0)
        ->and($charge->variableFee)->toBe(0.0);
});

it('ignores shops belonging to another site', function () {
    $other = Site::factory()->for_($this->owner)->create();

    Camera::factory()->count(4)->for($this->site)->create();
    payingShops($other, 5);

    expect($this->calculator->chargeForSite($this->site)->payingShopCount)->toBe(0);
});

it('does not bill deactivated cameras', function () {
    Camera::factory()->count(4)->for($this->site)->create();
    Camera::factory()->count(3)->for($this->site)->inactive()->create();

    // Seven cameras installed but only four running, so still Starter.
    expect($this->calculator->chargeForSite($this->site)->cameraCount)->toBe(4);
});

it('prefers a negotiated base fee over the published tier price', function () {
    Camera::factory()->count(20)->for($this->site)->create();

    SiteSubscription::factory()
        ->for($this->site)
        ->tier(BaseTier::Enterprise)
        ->create(['base_fee' => 9000.00]);

    expect($this->calculator->chargeForSite($this->site)->baseFee)->toBe(9000.00);
});

it('totals every site the owner runs', function () {
    $second = Site::factory()->for_($this->owner)->create(['name' => 'Mall B']);

    Camera::factory()->count(3)->for($this->site)->create();
    Camera::factory()->count(6)->for($second)->create();

    expect($this->calculator->chargesForOwner($this->owner))->toHaveCount(2)
        ->and($this->calculator->ownerTotal($this->owner))->toBe(5000.00);
});

it('splits shop revenue with the platform', function () {
    payingShops($this->site, 2);

    $split = $this->calculator->shopRevenueSplit($this->owner);

    // Two shops at the R400 default, 30% to the platform.
    expect($split['gross'])->toBe(800.00)
        ->and($split['platform_share'])->toBe(240.00)
        ->and($split['owner_share'])->toBe(560.00);
});

it('honours an owner-specific revenue share', function () {
    payingShops($this->site, 1);

    $this->owner->update(['settings' => ['platform_shop_revenue_share' => 0.5]]);

    expect($this->calculator->shopRevenueSplit($this->owner->fresh())['platform_share'])->toBe(200.00);
});

it('costs zero for a site with no active cameras', function () {
    // The site has no cameras attached at all. Owners setting up a new
    // property should not see a base fee land on their bill just for
    // creating the record.
    $charge = $this->calculator->chargeForSite($this->site);

    expect($charge->baseFee)->toBe(0.0)
        ->and($charge->cameraSurcharge)->toBe(0.0)
        ->and($charge->variableFee)->toBe(0.0)
        ->and($charge->total())->toBe(0.0);
});

it('re-derives the tier from the live camera count regardless of stored tier', function () {
    // Store an Enterprise tier on the subscription but only run three cameras
    // and leave base_fee at zero so the metered path (not the negotiated
    // override) is exercised. Metered billing should ignore the stored tier
    // and settle on Starter.
    SiteSubscription::factory()
        ->for($this->site)
        ->create([
            'base_tier' => \App\Enums\BaseTier::Enterprise,
            'base_fee' => 0,
        ]);

    Camera::factory()->count(3)->for($this->site)->create();

    $charge = $this->calculator->chargeForSite($this->site);

    expect($charge->tier)->toBe(\App\Enums\BaseTier::Starter)
        ->and($charge->baseFee)->toBe(1800.00);
});

it('prorates a site added mid-month by the days it was actually live', function () {
    // A site created on the 16th of a 30-day month should be billed for
    // roughly half the base fee, not the full amount. created_at is not in
    // Site's Fillable list, and Eloquent's timestamp handling wants to
    // override it on save, so we stamp the row directly through the query
    // builder to bypass both.
    $latecomer = Site::factory()->for_($this->owner)->create();
    Camera::factory()->count(3)->for($latecomer)->create();

    \Illuminate\Support\Facades\DB::table('sites')
        ->where('id', $latecomer->id)
        ->update(['created_at' => '2026-06-16 00:00:00']);

    $refreshed = $latecomer->fresh();

    $charge = $this->calculator->chargeForSite(
        $refreshed,
        \Illuminate\Support\Facades\Date::parse('2026-06-01'),
    );

    // 30-day month, site created on the 16th, ~half the base fee.
    expect($charge->prorationFactor)->toBeGreaterThan(0.4)
        ->and($charge->prorationFactor)->toBeLessThan(0.6)
        ->and($charge->baseFee)->toBeLessThan(1000.00)
        ->and($charge->baseFee)->toBeGreaterThan(700.00);
});

it('bills nothing when the site did not yet exist for the requested period', function () {
    $futureSite = Site::factory()->for_($this->owner)->create();
    Camera::factory()->count(5)->for($futureSite)->create();

    \Illuminate\Support\Facades\DB::table('sites')
        ->where('id', $futureSite->id)
        ->update(['created_at' => '2026-08-05 00:00:00']);

    // The July invoice run should skip this site entirely — it did not exist
    // during July.
    $charge = $this->calculator->chargeForSite(
        $futureSite->fresh(),
        \Illuminate\Support\Facades\Date::parse('2026-07-01'),
    );

    expect($charge->prorationFactor)->toBe(0.0)
        ->and($charge->total())->toBe(0.0);
});

// ── Security operator seats ────────────────────────────────────────────────

it('counts every security operator seat under an owner organization', function () {
    \App\Models\User::factory()->securityOperator($this->owner)->count(4)->create();

    // A guard in someone else's org must not leak in.
    \App\Models\User::factory()->securityOperator()->create();

    expect($this->calculator->securityOperatorSeatCount($this->owner))->toBe(4);
});

it('charges the configured seat rate for each operator', function () {
    \App\Models\User::factory()->securityOperator($this->owner)->count(3)->create();

    $expected = 3 * (float) config('trafficflow.security_operator_monthly_amount');

    expect($this->calculator->securityOperatorSeatCharge($this->owner))->toBe($expected);
});

it('collapses the seat charge to zero when the owner has no operators', function () {
    expect($this->calculator->securityOperatorSeatCharge($this->owner))->toBe(0.0);
});

it('rolls the seat charge into the owner monthly total', function () {
    Camera::factory()->count(3)->for($this->site)->create();
    \App\Models\User::factory()->securityOperator($this->owner)->count(2)->create();

    $seatTotal = 2 * (float) config('trafficflow.security_operator_monthly_amount');
    $siteTotal = $this->calculator->chargeForSite($this->site)->total();

    expect($this->calculator->ownerTotal($this->owner))->toBe(round($siteTotal + $seatTotal, 2));
});
