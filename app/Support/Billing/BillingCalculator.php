<?php

namespace App\Support\Billing;

use App\Enums\BaseTier;
use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\Scopes\SiteScope;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Works out what an owner owes for a month.
 *
 * Pricing is fully metered: an owner may add sites and cameras freely and the
 * bill just tracks what they actually run. A site's tier is re-derived from
 * its live camera count on every billing pass — Starter up to 4 cameras,
 * Standard to 8, Large to 16, Enterprise beyond, with a per-camera surcharge
 * once the Large ceiling is passed. On top of that, each site pays a variable
 * fee tied to how many paying shops the owner has resold access to.
 *
 * Sites created part-way through a month are prorated by day count so an owner
 * who onboards a mall on the 20th is not billed as if it ran the whole month.
 *
 * The shop count is always recomputed from paying subscriptions rather than
 * read from a stored column, so an owner cannot be billed for shops that
 * stopped paying, and cannot avoid being billed for ones that started.
 *
 * Runs unscoped: billing legitimately spans every site an organization owns,
 * including ones the operator has not selected in the site switcher.
 */
class BillingCalculator
{
    public function chargeForSite(Site $site, ?CarbonInterface $periodStart = null): SiteCharge
    {
        $period = $this->periodBounds($periodStart);
        $factor = $this->prorationFactor($site, $period);

        $cameras = $this->cameraCount($site);
        $payingShops = $this->payingShopCount($site);
        $subscription = $this->subscriptionFor($site);
        $owner = $site->organization;

        // Metered: always derive the tier from the live camera count. A stored
        // base_tier is left on the row as a display hint but is not the source
        // of truth for what the owner pays — the number of cameras is.
        $tier = BaseTier::forCameraCount($cameras);

        // A platform admin can flag an owner as "free" from the Owners page —
        // typically for pilots, staff dogfooding, or comped partner accounts.
        // Every fee collapses to zero without touching the site or camera
        // records, so lifting the flag restores normal billing exactly.
        if ($owner !== null && $owner->isOnFreeBillingPlan()) {
            return new SiteCharge(
                site: $site,
                tier: $tier,
                cameraCount: $cameras,
                payingShopCount: $payingShops,
                baseFee: 0.0,
                cameraSurcharge: 0.0,
                variableFee: 0.0,
                uncappedVariableFee: 0.0,
                variableFeeCap: null,
                prorationFactor: $factor,
            );
        }

        // Base fee resolution — most specific wins:
        //   1. Per-owner override (a hand-shaken flat fee that applies to
        //      every site the owner runs).
        //   2. Per-site negotiated base_fee on SiteSubscription (typically
        //      an Enterprise deal on one specific mall).
        //   3. Published tier price derived from the camera count.
        $ownerBaseOverride = $this->positiveOverride($owner, 'billing.base_fee_override');
        $negotiatedBase = $subscription !== null ? (float) $subscription->base_fee : 0.0;
        $publishedBase = $tier->baseFee();

        $baseFee = match (true) {
            $ownerBaseOverride !== null => $ownerBaseOverride,
            $negotiatedBase > 0.0 => $negotiatedBase,
            default => $publishedBase,
        };

        // Same precedence for the variable rate.
        $ownerRateOverride = $this->positiveOverride($owner, 'billing.variable_rate_override');
        $subscriptionRate = $subscription !== null && (float) $subscription->variable_rate_per_camera_per_subuser > 0
            ? (float) $subscription->variable_rate_per_camera_per_subuser
            : null;

        $rate = $ownerRateOverride
            ?? $subscriptionRate
            ?? (float) config('trafficflow.variable_rate_per_camera_per_subuser');

        // Cap: an owner-level override wins when set (owner-level of 0 is
        // read as "no cap explicitly set" — a real "always uncapped" answer
        // is left to the absence of both).
        $ownerCapOverride = $this->positiveOverride($owner, 'billing.variable_fee_cap_override');
        $cap = $ownerCapOverride
            ?? ($subscription?->variable_fee_cap === null ? null : (float) $subscription->variable_fee_cap);

        // Sites with no active cameras cost nothing — an owner setting up a new
        // property should not see a base fee until they actually plug something
        // in. Variable and surcharge lines derive from cameras too, so they
        // collapse to zero naturally in this branch.
        if ($cameras === 0) {
            return new SiteCharge(
                site: $site,
                tier: $tier,
                cameraCount: 0,
                payingShopCount: $payingShops,
                baseFee: 0.0,
                cameraSurcharge: 0.0,
                variableFee: 0.0,
                uncappedVariableFee: 0.0,
                variableFeeCap: $cap,
                prorationFactor: $factor,
            );
        }

        $uncapped = round($cameras * $payingShops * $rate, 2);
        $variableFee = $cap === null ? $uncapped : round(min($uncapped, $cap), 2);

        return new SiteCharge(
            site: $site,
            tier: $tier,
            cameraCount: $cameras,
            payingShopCount: $payingShops,
            baseFee: round($baseFee * $factor, 2),
            cameraSurcharge: round($this->cameraSurcharge($tier, $cameras) * $factor, 2),
            variableFee: round($variableFee * $factor, 2),
            uncappedVariableFee: round($uncapped * $factor, 2),
            variableFeeCap: $cap,
            prorationFactor: $factor,
        );
    }

    /**
     * Read a numeric owner-level override from settings, returning null when
     * it is unset, blank, or non-positive — the latter treated as "no
     * override" so an accidental zero in the DB doesn't wipe out billing.
     * "Free" is expressed via the separate `billing.free` flag, not via a
     * zero override.
     */
    protected function positiveOverride(?Organization $owner, string $key): ?float
    {
        if ($owner === null) {
            return null;
        }

        $value = $owner->setting($key);

        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;

        return $value > 0.0 ? $value : null;
    }

    /**
     * Every site the owner runs, so the consolidated invoice has one line each.
     *
     * @return Collection<int, SiteCharge>
     */
    public function chargesForOwner(Organization $owner, ?CarbonInterface $periodStart = null): Collection
    {
        return $this->sitesOf($owner)->map(fn (Site $site) => $this->chargeForSite($site, $periodStart));
    }

    public function ownerTotal(Organization $owner, ?CarbonInterface $periodStart = null): float
    {
        return round(
            $this->chargesForOwner($owner, $periodStart)->sum(fn (SiteCharge $charge) => $charge->total())
                + $this->securityOperatorSeatCharge($owner),
            2,
        );
    }

    /**
     * The number of security operator seats an owner is currently paying for.
     * Recomputed live so a seat removed today is not billed for tomorrow.
     */
    public function securityOperatorSeatCount(Organization $owner): int
    {
        return User::query()
            ->where('organization_id', $owner->getKey())
            ->where('role', UserRole::SecurityOperator)
            ->count();
    }

    /**
     * Flat charge: (seats) × (configured monthly rate). Independent of camera
     * count and shop count on purpose — a seat is a seat regardless of how
     * busy the site is. A free-plan owner pays nothing for seats either;
     * otherwise a pilot account would suddenly grow a seat bill the moment
     * they hired their first guard.
     */
    public function securityOperatorSeatCharge(Organization $owner): float
    {
        if ($owner->isOnFreeBillingPlan()) {
            return 0.0;
        }

        $count = $this->securityOperatorSeatCount($owner);

        if ($count === 0) {
            return 0.0;
        }

        return round($count * (float) config('trafficflow.security_operator_monthly_amount'), 2);
    }

    /**
     * What the owner keeps from its shops after the platform's share. This is
     * revenue to the owner, not a charge, so it never lands on their invoice.
     *
     * @return array{gross: float, platform_share: float, owner_share: float}
     */
    public function shopRevenueSplit(Organization $owner): array
    {
        $gross = round((float) ShopSubscription::query()
            ->whereIn('organization_id', $this->shopIdsOf($owner))
            ->where('status', SubscriptionStatus::Active)
            ->sum('monthly_amount'), 2);

        $share = (float) $owner->setting('platform_shop_revenue_share');
        $platform = round($gross * $share, 2);

        return [
            'gross' => $gross,
            'platform_share' => $platform,
            'owner_share' => round($gross - $platform, 2),
        ];
    }

    /**
     * Enterprise sites pay per camera above the Large ceiling; every other
     * tier is flat.
     */
    protected function cameraSurcharge(BaseTier $tier, int $cameras): float
    {
        $threshold = $tier->perCameraSurchargeAbove();

        if ($threshold === null || $cameras <= $threshold) {
            return 0.0;
        }

        return round(($cameras - $threshold) * BaseTier::ENTERPRISE_PER_CAMERA_FEE, 2);
    }

    /**
     * A fraction in (0, 1] describing how much of the given period the site
     * actually existed for. Returns 1.0 for any site that was already alive
     * when the period started, which is the common case.
     *
     * @param  array{start: CarbonInterface, end: CarbonInterface}  $period
     */
    protected function prorationFactor(Site $site, array $period): float
    {
        $createdAt = $site->created_at;

        if ($createdAt === null || $createdAt->lessThanOrEqualTo($period['start'])) {
            return 1.0;
        }

        // A site created after the period ends contributes nothing to the
        // invoice for that period — its first line will appear next month.
        if ($createdAt->greaterThan($period['end'])) {
            return 0.0;
        }

        $totalSeconds = (float) $period['end']->diffInSeconds($period['start'], absolute: true);
        $activeSeconds = (float) $period['end']->diffInSeconds($createdAt, absolute: true);

        if ($totalSeconds <= 0) {
            return 1.0;
        }

        // Round to 4 dp so a mid-month site rounds cleanly without drifting on
        // repeated recalculations.
        return round(min(1.0, max(0.0, $activeSeconds / $totalSeconds)), 4);
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface}
     */
    protected function periodBounds(?CarbonInterface $periodStart): array
    {
        $start = ($periodStart ?? Date::now())->copy()->startOfMonth();

        return [
            'start' => $start,
            'end' => $start->copy()->endOfMonth(),
        ];
    }

    /**
     * Only active cameras are billable: a device switched off in Settings is
     * not producing data and should not cost anything.
     */
    protected function cameraCount(Site $site): int
    {
        return Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->where('is_active', true)
            ->count();
    }

    /**
     * Shops on this site whose own subscription is actually being paid. A
     * trialing shop is not yet revenue and so does not raise the owner's fee.
     */
    protected function payingShopCount(Site $site): int
    {
        return ShopSubscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereIn('organization_id', Organization::query()
                ->select('id')
                ->where('type', OrganizationType::Shop)
                ->where('parent_site_id', $site->getKey()))
            ->count();
    }

    protected function subscriptionFor(Site $site): ?SiteSubscription
    {
        return SiteSubscription::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->first();
    }

    /**
     * @return EloquentCollection<int, Site>
     */
    protected function sitesOf(Organization $owner): EloquentCollection
    {
        return Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('organization_id', $owner->getKey())
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, int>
     */
    protected function shopIdsOf(Organization $owner): Collection
    {
        return Organization::query()
            ->where('type', OrganizationType::Shop)
            ->whereIn('parent_site_id', $this->sitesOf($owner)->modelKeys())
            ->pluck('id');
    }
}
