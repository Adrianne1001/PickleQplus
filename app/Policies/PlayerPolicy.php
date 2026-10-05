<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Player;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Any member of the player's club can manage players; everyone else gets 404.
 * viewAny/create take the club: Gate::authorize('viewAny', [Player::class, $club]).
 */
class PlayerPolicy
{
    public function viewAny(User $user, Club $club): Response
    {
        return $this->member($user, $club);
    }

    public function create(User $user, Club $club): Response
    {
        return $this->member($user, $club);
    }

    public function view(User $user, Player $player): Response
    {
        return $this->memberOfPlayersClub($user, $player);
    }

    public function update(User $user, Player $player): Response
    {
        return $this->memberOfPlayersClub($user, $player);
    }

    public function deactivate(User $user, Player $player): Response
    {
        return $this->memberOfPlayersClub($user, $player);
    }

    public function reactivate(User $user, Player $player): Response
    {
        return $this->memberOfPlayersClub($user, $player);
    }

    private function memberOfPlayersClub(User $user, Player $player): Response
    {
        $isMember = ClubMembership::query()
            ->where('club_id', $player->club_id)
            ->where('user_id', $user->id)
            ->exists();

        return $isMember
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function member(User $user, Club $club): Response
    {
        return $user->belongsToClub($club) ? Response::allow() : Response::denyAsNotFound();
    }
}
