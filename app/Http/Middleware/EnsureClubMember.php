<?php

namespace App\Http\Middleware;

use App\Models\Club;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Club-scoped route guard. Use on routes with a {club:slug} parameter, after
 * the auth + verified middleware.
 *
 * - Non-members (and unknown slugs) get 404, so club existence is not revealed.
 * - Binds the current club into the container (Club::current()).
 * - Remembers the club in users.current_club_id for the /dashboard redirect.
 *
 * Registered as Livewire persistent middleware (AppServiceProvider), so
 * /livewire/update requests re-check membership before any component action.
 */
class EnsureClubMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $club = $request->route('club');

        // On Livewire update requests, the route parameter may still be the raw
        // slug if binding has not run; resolve it and write it back to the route.
        if (is_string($club)) {
            $club = Club::query()->where('slug', $club)->first();

            if ($club instanceof Club) {
                $request->route()?->setParameter('club', $club);
            }
        }

        if (! $user instanceof User || ! $club instanceof Club || ! $user->belongsToClub($club)) {
            abort(404);
        }

        app()->instance(Club::CONTAINER_KEY, $club);

        if ($user->current_club_id !== $club->id) {
            User::query()->whereKey($user->id)->update(['current_club_id' => $club->id]);
            $user->current_club_id = $club->id;
            $user->syncOriginalAttribute('current_club_id');
        }

        return $next($request);
    }
}
