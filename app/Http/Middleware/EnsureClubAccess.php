<?php

namespace App\Http\Middleware;

use App\Models\Club;
use App\Services\GuestClubService;
use App\Support\GuestOwnerContext;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard for the session board, edit and results routes (Phase 14).
 *
 * - Normal club: exactly the old chain: login (with intended URL), verified (follows the
 *   Fortify email verification flag), then EnsureClubMember (non-members get 404).
 * - Guest club: needs the organizer cookie for that club (checked against the stored hash)
 *   and an unexpired club. It binds Club::current() and the GuestOwnerContext, which the
 *   Gate::before whitelist reads. Anything else is a 404, or the expired page once the
 *   cookie holder's club has expired or been purged.
 *
 * Registered as Livewire persistent middleware, so /livewire/update re-checks access.
 */
class EnsureClubAccess
{
    public function __construct(private readonly GuestClubService $guests, private readonly GuestOwnerContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        $club = $request->route('club');
        $slug = $club instanceof Club ? $club->slug : (is_string($club) ? $club : null);

        $guestClub = $slug === null ? null : Club::query()->where('slug', $slug)->where('is_guest', true)->first();

        if ($guestClub === null) {
            // The tombstone only speaks for a slug nobody holds now.
            if ($slug !== null && GuestClubService::isGone($slug)) {
                return $this->expired($request);
            }

            return $this->memberChain($request, $next);
        }

        if (! $this->guests->cookieMatches($request, $guestClub)) {
            abort(404);
        }

        if ($guestClub->isGuestExpired()) {
            return $this->expired($request);
        }

        $request->route()?->setParameter('club', $guestClub);
        app()->instance(Club::CONTAINER_KEY, $guestClub);
        $this->context->set($guestClub, $request->path());

        return $next($request);
    }

    private function memberChain(Request $request, Closure $next): Response
    {
        /** @var Response */
        return (new Pipeline(app()))
            ->send($request)
            ->through([Authenticate::class, EnsureEmailIsVerified::class, EnsureClubMember::class])
            ->then($next);
    }

    private function expired(Request $request): Response
    {
        // Page loads land on the friendly page. Livewire calls get 410 Gone, which the browser
        // hook turns into that page; a plain 404 stays a normal "not found" (e.g. a removed row).
        if ($request->isMethod('GET') && ! $request->headers->has('X-Livewire')) {
            return redirect()->route('guest.expired');
        }

        abort(410);
    }
}
