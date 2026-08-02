<?php

namespace App\Support\Billing;

use App\Models\Scopes\SiteScope;
use App\Models\SiteSubscription;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Collection;

/**
 * Answers "may this user still use the product?" for the paywall, and explains
 * why not when the answer is no.
 */
class SubscriptionStatusResolver
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * An owner keeps access while any one of their sites is paid up, so a
     * single lapsed site does not lock them out of the others. A shop's access
     * rests entirely on its own subscription.
     */
    public function hasAccess(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if ($user->isShopUser()) {
            $subscription = $user->organization?->shopSubscription;

            return $subscription !== null && $subscription->grantsAccess();
        }

        $subscriptions = $this->ownerSubscriptions($user);

        // A brand new owner with no subscription row yet is still finding their
        // feet, not delinquent.
        if ($subscriptions->isEmpty()) {
            return true;
        }

        return $subscriptions->contains(fn (SiteSubscription $s) => $s->grantsAccess());
    }

    /**
     * Sites the owner needs to settle, for the banner shown above the app.
     *
     * @return Collection<int, SiteSubscription>
     */
    public function lapsedSubscriptions(User $user): Collection
    {
        if (! $user->isOwnerAdmin()) {
            return collect();
        }

        return $this->ownerSubscriptions($user)
            ->reject(fn (SiteSubscription $s) => $s->grantsAccess())
            ->values();
    }

    public function reason(User $user): string
    {
        if ($user->isShopUser()) {
            $status = $user->organization?->shopSubscription?->status;

            return $status === null
                ? 'This shop does not have an active '.config('app.name').' subscription.'
                : "This shop's subscription is {$status->label()}.";
        }

        $lapsed = $this->lapsedSubscriptions($user);

        return $lapsed->isEmpty()
            ? 'Your subscription is not active.'
            : 'Every site on your account has a lapsed subscription.';
    }

    /**
     * @return Collection<int, SiteSubscription>
     */
    protected function ownerSubscriptions(User $user): Collection
    {
        $siteIds = $this->tenancy->user()?->is($user)
            ? $this->tenancy->accessibleSiteIds()
            : $user->organization?->sites()->withoutGlobalScope(SiteScope::class)->pluck('id')->all() ?? [];

        if ($siteIds === []) {
            return collect();
        }

        return SiteSubscription::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $siteIds)
            ->with('site')
            ->get();
    }
}
