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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Works out what an owner owes for a month.
 *
 * A site's charge has three parts: a base fee set by how many cameras it runs,
 * a per-camera surcharge once it outgrows the Large tier, and a variable fee
 * that scales with how many shops the owner has resold access to.
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
    public function chargeForSite(Site $site): SiteCharge
    {
        $cameras = $this->cameraCount($site);
        $payingShops = $this->payingShopCount($site);
        $subscription = $this->subscriptionFor($site);

        $tier = $subscription->base_tier ?? BaseTier::forCameraCount($cameras);

        // A subscription may carry a negotiated base fee, typically for
        // Enterprise. Fall back to the published tier price.
        $baseFee = $subscription !== null && (float) $subscription->base_fee > 0
            ? (float) $subscription->base_fee
            : $tier->baseFee();

        $rate = $subscription !== null && (float) $subscription->variable_rate_per_camera_per_subuser > 0
            ? (float) $subscription->variable_rate_per_camera_per_subuser
            : (float) config('trafficflow.variable_rate_per_camera_per_subuser');

        $cap = $subscription?->variable_fee_cap === null ? null : (float) $subscription->variable_fee_cap;

        $uncapped = round($cameras * $payingShops * $rate, 2);

        return new SiteCharge(
            site: $site,
            tier: $tier,
            cameraCount: $cameras,
            payingShopCount: $payingShops,
            baseFee: round($baseFee, 2),
            cameraSurcharge: $this->cameraSurcharge($tier, $cameras),
            variableFee: $cap === null ? $uncapped : round(min($uncapped, $cap), 2),
            uncappedVariableFee: $uncapped,
            variableFeeCap: $cap,
        );
    }

    /**
     * Every site the owner runs, so the consolidated invoice has one line each.
     *
     * @return Collection<int, SiteCharge>
     */
    public function chargesForOwner(Organization $owner): Collection
    {
        return $this->sitesOf($owner)->map(fn (Site $site) => $this->chargeForSite($site));
    }

    public function ownerTotal(Organization $owner): float
    {
        return round($this->chargesForOwner($owner)->sum(fn (SiteCharge $charge) => $charge->total()), 2);
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
