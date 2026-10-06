<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Owners and staff of the session's club can manage sessions; everyone else
 * gets 404. viewAny/create take the club: Gate::authorize('create', [PlaySession::class, $club]).
 */
class PlaySessionPolicy
{
    public function viewAny(User $user, Club $club): Response
    {
        return $this->member($user, $club);
    }

    public function create(User $user, Club $club): Response
    {
        return $this->member($user, $club);
    }

    public function view(User $user, PlaySession $session): Response
    {
        return $this->memberOfSessionsClub($user, $session);
    }

    public function update(User $user, PlaySession $session): Response
    {
        return $this->memberOfSessionsClub($user, $session);
    }

    public function delete(User $user, PlaySession $session): Response
    {
        return $this->memberOfSessionsClub($user, $session);
    }

    /** Start, end, check players in and out, and run the board. */
    public function manage(User $user, PlaySession $session): Response
    {
        return $this->memberOfSessionsClub($user, $session);
    }

    private function memberOfSessionsClub(User $user, PlaySession $session): Response
    {
        $club = $session->club;

        return $club !== null ? $this->member($user, $club) : Response::denyAsNotFound();
    }

    private function member(User $user, Club $club): Response
    {
        return $user->belongsToClub($club) ? Response::allow() : Response::denyAsNotFound();
    }
}
