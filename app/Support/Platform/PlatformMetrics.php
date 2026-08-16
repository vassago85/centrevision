<?php

namespace App\Support\Platform;

use App\Enums\InvoiceStatus;
use App\Enums\OrganizationType;
use App\Enums\PayoutStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Camera;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PartnerPayout;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\Visit;
use App\Support\Billing\BillingCalculator;
use Illuminate\Support\Collection;

/**
 * Cross-tenant numbers for the platform admin.
 *
 * Everything here deliberately reaches past the site scope, which is safe
 * because the only route that reaches it is behind the platform_admin role.
 */
class PlatformMetrics
{
    public function __construct(protected BillingCalculator $calculator) {}

    /**
     * Recurring revenue the platform bills this month: what owners owe for
     * their sites, plus the platform's cut of the shop fees they resell.
     */
    public function monthlyRecurringRevenue(): float
    {
        return round($this->owners()->sum(
            fn (Organization $owner) => $this->calculator->ownerTotal($owner)
                + $this->calculator->shopRevenueSplit($owner)['platform_share'],
        ), 2);
    }

    /**
     * @return array<string, int|float>
     */
    public function counts(): array
    {
        return [
            'owners' => Organization::query()->where('type', OrganizationType::Owner)->count(),
            'sites' => Site::query()->withoutGlobalScope(SiteScope::class)->count(),
            'cameras' => Camera::query()->withoutGlobalScope(SiteScope::class)->where('is_active', true)->count(),
            'shops' => ShopSubscription::query()->where('status', SubscriptionStatus::Active)->count(),
            'partners' => Partner::query()->count(),
        ];
    }

    public function outstanding(): float
    {
        return round((float) Invoice::query()
            ->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::Failed])
            ->sum('amount'), 2);
    }

    public function payoutsDue(): float
    {
        return round((float) PartnerPayout::query()
            ->where('status', PayoutStatus::Pending)
            ->sum('commission_amount'), 2);
    }

    /**
     * Ingestion health, so a platform admin can see the pipeline is alive
     * without opening a single tenant.
     *
     * @return array{events: int, visits: int, silent_cameras: int}
     */
    public function ingestionHealth(): array
    {
        $since = now()->subDay();

        return [
            'events' => PlateEvent::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('captured_at', '>=', $since)
                ->count(),
            'visits' => Visit::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('entered_at', '>=', $since)
                ->count(),
            'silent_cameras' => Camera::query()
                ->withoutGlobalScope(SiteScope::class)
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('last_event_at')
                    ->orWhere('last_event_at', '<', now()->subMinutes((int) config('trafficflow.camera_stale_after_minutes'))))
                ->count(),
        ];
    }

    /**
     * One row per owner for the Owners page.
     *
     * @return Collection<int, OwnerSummary>
     */
    public function ownerSummaries(): Collection
    {
        return $this->owners()->map(function (Organization $owner): OwnerSummary {
            $charges = $this->calculator->chargesForOwner($owner);

            return new OwnerSummary(
                organization: $owner,
                siteCount: $charges->count(),
                cameraCount: (int) $charges->sum(fn ($charge) => $charge->cameraCount),
                payingShopCount: (int) $charges->sum(fn ($charge) => $charge->payingShopCount),
                monthlyCharge: round($charges->sum(fn ($charge) => $charge->total()), 2),
                platformShopShare: $this->calculator->shopRevenueSplit($owner)['platform_share'],
                lapsed: $this->hasLapsedSubscription($owner),
                partner: $owner->referredByPartner,
                isFree: $owner->isOnFreeBillingPlan(),
                hasCustomPlan: $owner->hasCustomBillingPlan(),
            );
        })->sortByDesc(fn (OwnerSummary $summary) => $summary->totalToPlatform())->values();
    }

    /**
     * @return Collection<int, Organization>
     */
    protected function owners(): Collection
    {
        return Organization::query()
            ->where('type', OrganizationType::Owner)
            ->with('referredByPartner')
            ->orderBy('name')
            ->get();
    }

    protected function hasLapsedSubscription(Organization $owner): bool
    {
        return SiteSubscription::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $owner->sites()->select('id'))
            ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Canceled])
            ->exists();
    }
}
