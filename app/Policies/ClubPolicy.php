<?php

namespace App\Policies;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Non-members are denied as "not found" (404) so club existence is not revealed.
 * Owners can do everything; staff can only view.
 */
class ClubPolicy
{
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function view(User $user, Club $club): Response
    {
        return $user->belongsToClub($club) ? Response::allow() : Response::denyAsNotFound();
    }

    /** Any member may remove themselves from the club (last-owner rule enforced in ClubService). */
    public function leave(User $user, Club $club): Response
    {
        return $this->view($user, $club);
    }

    public function update(User $user, Club $club): Response
    {
        return $this->ownerOnly($user, $club);
    }

    public function delete(User $user, Club $club): Response
    {
        return $this->ownerOnly($user, $club);
    }

    public function manageMembers(User $user, Club $club): Response
    {
        return $this->ownerOnly($user, $club);
    }

    /** Settings including star bands and invites. */
    public function manageSettings(User $user, Club $club): Response
    {
        return $this->ownerOnly($user, $club);
    }

    private function ownerOnly(User $user, Club $club): Response
    {
        $role = $user->roleIn($club);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role === ClubRole::Owner ? Response::allow() : Response::deny();
    }
}
