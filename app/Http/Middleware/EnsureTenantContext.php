<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads the tenant context for the request.
 *
 * Until this runs the Tenancy singleton is dormant and the SiteScope global
 * scope does nothing, which is what console and queue contexts want. Every
 * authenticated web route must go through here.
 */
class EnsureTenantContext
{
    public const SESSION_KEY = 'tenancy.site_id';

    public function __construct(protected Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $this->tenancy->setUser($user);

        // setCurrentSiteId discards a site the user cannot reach, so a tampered
        // session value narrows access at worst, never widens it.
        $this->tenancy->setCurrentSiteId($request->session()->get(self::SESSION_KEY));

        // Write the resolved value back, so a stale id is cleared rather than
        // silently ignored on every subsequent request.
        $request->session()->put(self::SESSION_KEY, $this->tenancy->currentSiteId());

        // A tenant user whose organization has no site cannot be shown
        // anything meaningful, and must not fall through to unscoped queries.
        if (! $user->isPlatformAdmin() && $this->tenancy->sites()->isEmpty()) {
            abort(403, 'Your account is not linked to a site yet.');
        }

        return $next($request);
    }
}
