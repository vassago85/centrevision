<?php

use App\Http\Middleware\AuthenticateHikCamera;
use App\Http\Middleware\EnsureSubscriptionActive;
use App\Http\Middleware\EnsureTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
            'subscribed' => EnsureSubscriptionActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'auth.hik-camera' => AuthenticateHikCamera::class,
        ]);

        // Production sits behind Nginx Proxy Manager (or any reverse proxy)
        // which terminates TLS and forwards over plain HTTP. Without this,
        // Laravel doesn't see X-Forwarded-Proto:https, so url()/asset()
        // emit http:// URLs and the browser blocks them as mixed content.
        // '*' trusts any upstream; scope it if the deployment target ever
        // exposes the app to something other than a controlled proxy.
        $middleware->trustProxies(at: '*');

        // Neither the payment gateway nor Hikvision cameras hold a session,
        // so there is no CSRF token to send. Each endpoint carries its own
        // authentication: signature check for Paystack, HTTP Basic against
        // the per-camera webhook_secret for Hikvision.
        $middleware->validateCsrfTokens(except: [
            'webhooks/paystack',
            'webhooks/hik/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
