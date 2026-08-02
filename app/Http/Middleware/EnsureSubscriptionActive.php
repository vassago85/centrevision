<?php

namespace App\Http\Middleware;

use App\Support\Billing\SubscriptionStatusResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a tenant whose subscription has lapsed to the paywall.
 *
 * Billing itself stays reachable, otherwise an owner could not pay their way
 * back in.
 */
class EnsureSubscriptionActive
{
    public function __construct(protected SubscriptionStatusResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isPlatformAdmin()) {
            return $next($request);
        }

        if ($this->resolver->hasAccess($user)) {
            return $next($request);
        }

        return redirect()->route('paywall');
    }
}
