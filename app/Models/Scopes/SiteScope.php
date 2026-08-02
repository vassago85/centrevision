<?php

namespace App\Models\Scopes;

use App\Models\Contracts\SiteScoped;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains site-owned records to the sites the current tenant may reach.
 *
 * Dormant when there is no tenant context, so jobs and seeders see everything.
 *
 * @implements Scope<Model&SiteScoped>
 */
class SiteScope implements Scope
{
    /**
     * @param  Builder<covariant Model&SiteScoped>  $builder
     * @param  Model&SiteScoped  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->isActive()) {
            return;
        }

        // scopeSiteIds honours the site switcher, so selecting a site narrows
        // every query rather than leaving each page to remember to filter.
        // An empty list still applies as a constraint, so a user with no
        // reachable sites matches nothing rather than everything.
        $model->applySiteScope($builder, $tenancy->scopeSiteIds());
    }
}
