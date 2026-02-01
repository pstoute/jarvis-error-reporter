<?php

/**
 * Example: Setting Jarvis Context in Middleware
 *
 * This example shows how to automatically add context to all error reports
 * based on the current request. Perfect for multi-tenant applications or
 * tracking specific request metadata.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use StouteWebSolutions\JarvisErrorReporter\Facades\Jarvis;

class JarvisContextMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Set authenticated user context
        if ($user = $request->user()) {
            Jarvis::setUser(
                id: $user->id,
                email: $user->email,
                name: $user->name
            );
        }

        // Multi-tenant context
        if ($tenant = $request->tenant) {
            Jarvis::setContext([
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'tenant_plan' => $tenant->plan,
                'tenant_status' => $tenant->status,
                'subscription_expires_at' => $tenant->subscription_expires_at?->toDateString(),
            ]);
        }

        // API versioning context
        if ($apiVersion = $request->header('X-API-Version')) {
            Jarvis::setContext([
                'api_version' => $apiVersion,
            ]);
        }

        // Request metadata
        Jarvis::setContext([
            'route_name' => $request->route()?->getName(),
            'route_action' => $request->route()?->getActionName(),
            'request_id' => $request->header('X-Request-ID') ?? uniqid('req_'),
            'user_agent_platform' => $this->detectPlatform($request->userAgent()),
        ]);

        // Feature flags (if you're using a feature flag system)
        if (method_exists($request, 'features')) {
            Jarvis::setContext([
                'enabled_features' => $request->features()->enabled(),
            ]);
        }

        return $next($request);
    }

    /**
     * Simple platform detection from user agent.
     */
    private function detectPlatform(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'unknown';
        }

        if (str_contains($userAgent, 'Mobile')) {
            return 'mobile';
        }

        if (str_contains($userAgent, 'Tablet')) {
            return 'tablet';
        }

        return 'desktop';
    }
}

/*
 * To use this middleware, register it in app/Http/Kernel.php:
 *
 * protected $middleware = [
 *     // ...
 *     \App\Http\Middleware\JarvisContextMiddleware::class,
 * ];
 *
 * Or apply it to specific route groups:
 *
 * Route::middleware(['web', JarvisContextMiddleware::class])->group(function () {
 *     // Your routes
 * });
 */
