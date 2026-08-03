<?php

namespace App\Support\Billing;

use App\Enums\BaseTier;
use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\Scopes\SiteScope;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
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

        // Metered: always derive the tier from the live camera count. A stored
        // base_tier is left on the row as a display hint but is not the source
        // of truth for what the owner pays — the number of cameras is.
        $tier = BaseTier::forCameraCount($cameras);

        // A subscription may still carry a negotiated flat base fee (typically
        // for hand-shaken Enterprise deals). If it is set to something above
        // zero, honour it; otherwise use the published tier price.
        $publishedBase = $tier->baseFee();
        $negotiatedBase = $subscription !== null ? (float) $subscription->base_fee : 0.0;
        $baseFee = $negotiatedBase > 0.0 ? $negotiatedBase : $publishedBase;

        $rate = $subscription !== null && (float) $subscription->variable_rate_per_camera_per_subuser > 0
            ? (float) $subscription->variable_rate_per_camera_per_subuser
            : (float) config('trafficflow.variable_rate_per_camera_per_subuser');

        $cap = $subscription?->variable_fee_cap === null ? null : (float) $subscription->variable_fee_cap;

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
            $this->chargesForOwner($owner, $periodStart)->sum(fn (SiteCharge $charge) => $charge->total()),
            2,
        );
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
