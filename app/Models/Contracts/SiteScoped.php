<?php

namespace App\Models\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that ultimately belongs to a site and can therefore be narrowed to
 * the sites a tenant may reach.
 *
 * The ScopedToSite trait supplies the implementation; this contract exists so
 * SiteScope can state what it needs from the models it is applied to.
 */
interface SiteScoped
{
    /**
     * The column holding the site key.
     */
    public function siteScopeColumn(): string;

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  array<int, int>  $siteIds
     */
    public function applySiteScope(Builder $builder, array $siteIds): void;
}
