<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\User;
use App\Services\GuestClubService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;

/**
 * "Sign up to keep this session" (Phase 14): turns a guest club into a normal club the signed-in
 * user owns. Needs the organizer cookie for that club. A logged-out visitor is sent to register
 * (or log in), and the intended URL brings them back here.
 */
class GuestClaimController extends Controller
{
    public function __construct(private readonly GuestClubService $guests) {}

    public function show(Request $request, Club $club): View|RedirectResponse
    {
        $this->authorizeCookie($request, $club);

        if ($club->isGuestExpired()) {
            return redirect()->route('guest.expired');
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->guest(route('register'));
        }
        if (! $user->hasVerifiedEmail()) {
            return redirect()->guest(route('verification.notice'));
        }

        return view('guest.claim', ['club' => $club, 'session' => $club->playSessions()->oldest('id')->first()]);
    }

    public function store(Request $request, Club $club): RedirectResponse
    {
        $token = $this->authorizeCookie($request, $club);

        $input = Validator::make($request->all(), ['name' => ['nullable', 'string', 'max:120']])->validate();
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $claimed = $this->guests->claim($club, $user, $token, isset($input['name']) ? (string) $input['name'] : null);
        $session = $claimed->playSessions()->oldest('id')->first();

        Cookie::queue(Cookie::forget(GuestClubService::cookieName($club)));

        return redirect($session === null ? route('clubs.show', $claimed) : route('clubs.sessions.show', [$claimed, $session]));
    }

    /**
     * 404 unless the request carries this guest club's organizer cookie. Returns the token.
     */
    private function authorizeCookie(Request $request, Club $club): string
    {
        $token = $this->guests->tokenFromRequest($request, $club);
        abort_unless($token !== null && $this->guests->cookieMatches($request, $club), 404);

        return $token;
    }
}
