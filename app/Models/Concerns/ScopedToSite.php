<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Applied to every model that ultimately belongs to a site. Pair with
 * #[ScopedBy(SiteScope::class)] and the SiteScoped contract to enforce tenancy
 * at the query level.
 */
trait ScopedToSite
{
    /**
     * The column holding the site key. Overridden by Site itself, and by
     * models that reach a site indirectly.
     */
    public function siteScopeColumn(): string
    {
        return 'site_id';
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  array<int, int>  $siteIds
     */
    public function applySiteScope(Builder $builder, array $siteIds): void
    {
        $builder->whereIn(
            $builder->qualifyColumn($this->siteScopeColumn()),
            $siteIds,
        );
    }

    /**
     * Explicitly limit a query to a set of sites, on top of any tenant scope.
     *
     * @param  Builder<static>  $builder
     * @param  array<int, int>  $siteIds
     * @return Builder<static>
     */
    public function scopeForSites(Builder $builder, array $siteIds): Builder
    {
        return $builder->whereIn(
            $builder->qualifyColumn($this->siteScopeColumn()),
            $siteIds,
        );
    }
}
