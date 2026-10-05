<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Send the user to their current club, else their first club,
     * else to "create your first club".
     */
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $club = null;
        if ($user->current_club_id !== null) {
            $club = $user->clubs()->whereKey($user->current_club_id)->first();
        }
        $club ??= $user->clubs()->orderBy('clubs.name')->first();

        return $club instanceof Club
            ? redirect()->route('clubs.show', $club)
            : redirect()->route('clubs.create');
    }
}
