<?php

use App\Http\Middleware\EnsureClubAccess;
use App\Http\Middleware\EnsureClubMember;
use App\Http\Middleware\ThrottleRegistration;
use App\Services\GuestClubService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'club.member' => EnsureClubMember::class,
            'club.access' => EnsureClubAccess::class,
        ]);

        // Runs before route binding, like `auth` does, so access is decided before a {session} is looked up.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureClubAccess::class);

        $middleware->web(append: [
            ThrottleRegistration::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The public queue and TV of a purged guest club say "expired" instead of a bare 404 (P14.9).
        // Check-in has no club slug in its URL, so it keeps its own "closed" page.
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            $slug = $request->route('club');

            if ($request->isMethod('GET') && $request->routeIs('public.queue', 'public.tv') && is_string($slug) && GuestClubService::isGone($slug)) {
                return redirect()->route('guest.expired');
            }

            return null;
        });
    })
    ->booted(function (): void {
        // `composer dev` (php artisan dev) also runs the scheduler, so guest
        // sessions get purged locally. Production uses the cron entry (P6.3).
        DevCommands::artisan('schedule:work', 'schedule');
    })
    ->create();
