<?php

namespace App\Http\Controllers;

use App\Models\Club;
use Illuminate\Http\RedirectResponse;

/**
 * `/c/{club}` has no page of its own: send visitors to the club's public sessions list.
 */
class PublicClubRedirectController extends Controller
{
    public function __invoke(Club $club): RedirectResponse
    {
        return redirect()->route('public.sessions', $club);
    }
}
