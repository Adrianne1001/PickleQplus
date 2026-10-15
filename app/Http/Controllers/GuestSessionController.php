<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Services\GuestClubService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Guest sessions (Phase 14): start one without an account, open it on another device
 * through the private organizer link, and the "expired" landing page.
 */
class GuestSessionController extends Controller
{
    public function __construct(private readonly GuestClubService $guests) {}

    /**
     * Create a guest club with one draft session, set the organizer cookie and open the board.
     * Limited per IP per hour (config pickleq.guest.starts_per_hour). A signed-in user is sent to create a club instead.
     */
    public function start(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('clubs.create');
        }

        // Limit per IP per hour. Done here (not as route middleware) so the answer is a friendly
        // error on the form, not a bare 429 page. Every attempt counts, including rejected ones.
        $key = 'guest-start:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, (int) config('pickleq.guest.starts_per_hour'))) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return redirect()->back()->withInput($request->except(CreateNewUser::HONEYPOT))->withErrors([
                'guest' => "Too many guest sessions started from this network. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').' or sign up.',
            ], 'guest');
        }
        RateLimiter::hit($key, 3600);

        Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:120'],
            // Honeypot: hidden from people, so any value means a bot.
            CreateNewUser::HONEYPOT => ['prohibited'],
        ], [
            CreateNewUser::HONEYPOT.'.prohibited' => 'This could not be completed.',
        ])->validateWithBag('guest');

        try {
            $started = $this->guests->start($request->string('name')->toString());
        } catch (ValidationException $e) {
            return redirect()->back()->withInput($request->except(CreateNewUser::HONEYPOT))->withErrors($e->errors(), 'guest');
        }

        return redirect()
            ->route('clubs.sessions.show', [$started['club'], $started['session']])
            ->withCookie($this->guests->cookieFor($started['club'], $started['token']));
    }

    /**
     * The private organizer link: sets the organizer cookie on this device, then opens the
     * board so the token leaves the address bar. An unknown or expired link shows the expired page.
     */
    public function enter(string $token): RedirectResponse
    {
        $club = $this->guests->findByToken($token);
        $session = $club?->playSessions()->oldest('id')->first();

        if ($club === null || $session === null || $club->isGuestExpired()) {
            return redirect()->route('guest.expired');
        }

        return redirect()
            ->route('clubs.sessions.show', [$club, $session])
            ->withCookie($this->guests->cookieFor($club, $token));
    }

    public function expired(): Response
    {
        return response()->view('guest.expired', [], 410);
    }
}
