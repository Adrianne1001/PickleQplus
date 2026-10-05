<?php

namespace App\Services;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\User;
use App\Notifications\ClubInvitationNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff invitations. Tokens are random, single-use and stored only as a
 * SHA-256 hash; the plain token exists only in the emailed link.
 */
class InvitationService
{
    public function __construct(private readonly ClubService $clubs) {}

    /**
     * Invite an email to the club. A pending invite for the same club and
     * email is refreshed (new token, role, expiry) instead of duplicated.
     *
     * @throws ValidationException when the email already belongs to a member,
     *                             or the club hit its hourly invite limit (key "email").
     */
    public function invite(Club $club, User $inviter, string $email, ClubRole $role): ClubInvitation
    {
        $email = Str::lower(trim($email));

        $isMember = $club->users()->whereRaw('lower(users.email) = ?', [$email])->exists();
        if ($isMember) {
            throw ValidationException::withMessages(['email' => 'That person is already a member of this club.']);
        }

        $this->throttle($club->id);

        $plain = $this->newToken();

        $invitation = DB::transaction(function () use ($club, $inviter, $email, $role, $plain): ClubInvitation {
            // Serialize concurrent invites for this club, so the find-or-create
            // below cannot produce two pending rows for one email.
            Club::query()->whereKey($club->id)->lockForUpdate()->first();

            $invitation = $club->invitations()
                ->whereNull('accepted_at')
                ->where('email', $email)
                ->first() ?? new ClubInvitation(['email' => $email]);

            $invitation->club()->associate($club);
            $invitation->fill(['role' => $role, 'invited_by' => $inviter->id, 'expires_at' => $this->expiry()]);
            $invitation->forceFill(['token_hash' => $this->hash($plain)])->save();

            return $invitation;
        });

        $this->send($invitation, $plain);

        return $invitation;
    }

    /**
     * Issue a new token and expiry and email it again. The old link stops working.
     *
     * @throws ValidationException when the invitation was already accepted,
     *                             or the club hit its hourly invite limit (key "email").
     */
    public function resend(ClubInvitation $invitation): ClubInvitation
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was already accepted.']);
        }

        $this->throttle($invitation->club_id);

        $plain = $this->newToken();
        $invitation->forceFill([
            'token_hash' => $this->hash($plain),
            'expires_at' => $this->expiry(),
        ])->save();

        $this->send($invitation, $plain);

        return $invitation;
    }

    /**
     * Withdraw a pending invitation (the row is deleted; accepted ones are kept).
     */
    public function revoke(ClubInvitation $invitation): void
    {
        if (! $invitation->isAccepted()) {
            $invitation->delete();
        }
    }

    /**
     * Any invitation (pending, expired or accepted) for a plain token.
     */
    public function findByToken(string $token): ?ClubInvitation
    {
        return ClubInvitation::query()->with('club')->where('token_hash', $this->hash($token))->first();
    }

    /**
     * The invitation for a plain token, only while it can still be accepted.
     */
    public function findPendingByToken(string $token): ?ClubInvitation
    {
        $invitation = $this->findByToken($token);

        return $invitation?->isPending() ? $invitation : null;
    }

    /**
     * Accept an invitation: single-use, race-safe, and only for the invited
     * (verified) email. Adds the membership and makes the club current.
     * The result says whether the user was newly added or already a member.
     *
     * @throws ValidationException
     */
    public function accept(ClubInvitation $invitation, User $user): InvitationAcceptResult
    {
        return DB::transaction(function () use ($invitation, $user): InvitationAcceptResult {
            // Same lock order as invite(): club first, then the invitation.
            Club::query()->whereKey($invitation->club_id)->lockForUpdate()->first();

            $locked = ClubInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isAccepted()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation has already been used.']);
            }
            // A resend rotates the token; the older link no longer counts.
            if (! hash_equals($locked->token_hash, $invitation->token_hash)) {
                throw ValidationException::withMessages(['invitation' => 'This invitation link was replaced by a newer one. Use the latest email.']);
            }
            if ($locked->isExpired()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation has expired.']);
            }
            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['invitation' => 'Verify your email address before accepting an invitation.']);
            }
            if (Str::lower($user->email) !== Str::lower($locked->email)) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation was sent to '.self::maskEmail($locked->email).'. Log in with that account to accept it.',
                ]);
            }

            $club = Club::query()->findOrFail($locked->club_id);

            // No-op for an existing member: the invite is consumed, the role stays as it is.
            $joined = $this->clubs->addMember($club, $user, $locked->role);

            $locked->forceFill(['accepted_at' => now()])->save();
            $invitation->forceFill(['accepted_at' => $locked->accepted_at]);

            User::query()->whereKey($user->id)->update(['current_club_id' => $club->id]);
            $user->current_club_id = $club->id;

            return new InvitationAcceptResult($club, $joined);
        });
    }

    /**
     * "jane@example.com" becomes "j***@example.com".
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * Counts one invite email against the club's hourly limit (shared by
     * invite and resend, for every caller, Livewire or HTTP).
     *
     * @throws ValidationException
     */
    private function throttle(int $clubId): void
    {
        $key = 'invitations:service:club:'.$clubId;

        if (RateLimiter::tooManyAttempts($key, (int) config('pickleq.invitations_per_hour'))) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw ValidationException::withMessages([
                'email' => 'This club has sent too many invitations. Try again in '.$minutes.' '.Str::plural('minute', $minutes).'.',
            ]);
        }

        RateLimiter::hit($key, 3600);
    }

    private function send(ClubInvitation $invitation, string $plain): void
    {
        $club = $invitation->club()->firstOrFail();
        $inviter = $invitation->inviter()->first();

        Notification::route('mail', $invitation->email)
            ->notify(new ClubInvitationNotification($invitation, $plain, $club->name, $inviter?->name));
    }

    private function newToken(): string
    {
        return Str::random(40);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function expiry(): CarbonInterface
    {
        return now()->addDays((int) config('pickleq.invitation_ttl_days'));
    }
}
