<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the "registration" rate limiter (5 per hour per IP) to Fortify's
 * register POST. Fortify has no config key for a registration limiter, so
 * this is appended to the web group and only acts on that one route.
 */
class ThrottleRegistration
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs('register.store')) {
            return $this->throttle->handle($request, $next, 'registration');
        }

        return $next($request);
    }
}
