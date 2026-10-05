<?php

namespace App\Http\Controllers;

use App\Models\ClubInvitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public invite landing page and acceptance. Lives outside the club group
 * because the invitee is not a member yet.
 */
class InvitationAcceptController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return $this->unavailable($invitation);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            // Come back here after login, registration and email verification.
            $request->session()->put('url.intended', route('invitations.show', ['token' => $token]));

            return response()->view('invitations.guest', [
                'invitation' => $invitation,
                'club' => $invitation->club,
                'maskedEmail' => InvitationService::maskEmail($invitation->email),
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            $request->session()->put('url.intended', route('invitations.show', ['token' => $token]));

            return redirect()->route('verification.notice');
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            return response()->view('invitations.wrong-account', [
                'club' => $invitation->club,
                'maskedEmail' => InvitationService::maskEmail($invitation->email),
                'currentEmail' => $user->email,
            ], 403);
        }

        return response()->view('invitations.show', [
            'invitation' => $invitation,
            'club' => $invitation->club,
            'token' => $token,
            'existingRole' => $user->roleIn($invitation->club ?? abort(404)),
        ]);
    }

    public function accept(Request $request, string $token): Response
    {
        $user = $request->user() ?? abort(401);
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return $this->unavailable($invitation);
        }

        if (! $user->hasVerifiedEmail()) {
            $request->session()->put('url.intended', route('invitations.show', ['token' => $token]));

            return redirect()->route('verification.notice');
        }

        try {
            $result = $this->invitations->accept($invitation, $user);
        } catch (ValidationException $e) {
            return redirect()->route('invitations.show', ['token' => $token])
                ->withErrors($e->errors());
        }

        $club = $result->club;

        return redirect()->route('clubs.show', $club)->with(
            'flash',
            $result->joined ? 'You joined '.$club->name.'.' : "You're already a member of {$club->name}.",
        );
    }

    private function unavailable(?ClubInvitation $invitation): Response
    {
        $reason = match (true) {
            $invitation === null => 'invalid',
            $invitation->isAccepted() => 'used',
            default => 'expired',
        };

        return response()->view('invitations.unavailable', ['reason' => $reason], 410);
    }
}
