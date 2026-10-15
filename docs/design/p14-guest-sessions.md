# Phase 14 — Guest sessions (design)

> **Status:** in progress on `feature/p14-guest-sessions`. The binding rules and checklist are in `PLAN.md` (Phase 14 rules, checklist P14.x). This doc keeps the reasoning and the implementation notes. Phase 13 shipped as auth feature toggles only (no Google sign-in), so the claim flow covers register and login, with the verification flag on or off.

## Why a hidden guest club

Everything in the app hangs off a club: roster, sessions, board, QR, TV and the public pages. A hidden guest club with no `club_user` rows reuses all of it unchanged. Deleting a club already cascades in the database to sessions, players, session_players, matches, match_players and dupr_exports, so a purge is one delete.

**Rejected: a throwaway "guest user" that gets logged in.** It would make every route that requires a login reachable (dashboard, `clubs/create`, settings, invites). Fortify's guest-only register page would also block the claim step. With a cookie token, guests reach only the routes we open on purpose. Everything else stays closed by default.

## Activity and expiry

- One listener on `PlaySessionChanged`, next to `BroadcastSessionUpdate`, called `TouchGuestActivity`. It sets `guest_expires_at = min(now + idle_hours, created_at + max_hours)`. It acts only on guest clubs, and never on one that has already expired, so a late self check-in can't bring an expired club back.
- Every service write already fires this event after commit. Rolled-back actions never fire it. Page views and polling never fire it.
- Middleware treats `guest_expires_at <= now()` as expired, even before the purge has run.

## Access

- The same URLs and route names are kept, so the roughly 19 `route('clubs.sessions.*')` calls in the components and views work unchanged.
- `EnsureClubAccess` (alias `club.access`) handles the board, edit and results routes:
  - **Guest club:** checks the cookie hash against the club and that the club isn't expired. On success it binds `Club::current()` and a `GuestOwnerContext`. Otherwise: the expired page (tombstone) or 404.
  - **Normal club:** the existing chain (login with intended URL → verified → `EnsureClubMember`).
- The middleware is added to Livewire's persistent middleware, so `/livewire/update` requests re-check access.
- `Gate::before(?User $user, $ability, $args)` returns true only when a `GuestOwnerContext` is bound, the ability is whitelisted (PlaySession `view`/`update`/`manage`, Player `create`) and the argument's club is the context's club. Otherwise it returns null. Member behaviour can't change.
- Services that take `User $actor` become `?User` where a guest acts: `PlaySessionService::regenerateCheckinToken`, `resetTvLink` and `CheckInService::removeCheckIn`. The `abort_unless($user instanceof User)` guards in `CheckInPanel::removeCheckIn` and `CheckInQr::regenerate`/`resetTvLink` go.
- These stay member-only, because guests have no `club_user` row: DUPR pages and download (plus an explicit refusal in the export service), staff stats, player index and profile, roster import (its preview store is keyed by user), members and invites, club settings, the sessions index and create, and session delete.
- The public leaderboard, public sessions list and `PublicClubRedirectController` add `abort_if($club->is_guest, 404)`. The public club header tabs and prev/next links are hidden for guest clubs.

## Limits (in services)

- **One session:** `PlaySessionService::create` locks the club row.
- **Courts:** `validated()` takes the max-courts value (guest 6, otherwise `COURTS_MAX`). This covers create, update and the skill-group resize.
- **Checked in:** `CheckInService::checkIn`, inside the session lock, when the entry is new or `left`. Every path goes through it (self check-in, session form, quick-add), so a failed check-in rolls back a newly created player too.
- **Roster:** `PlayerService::create` and `SelfCheckInService::register`.

## Purge

- `pickleq:purge-guest-clubs` runs every 5 minutes with `withoutOverlapping`, in chunks of 50.
- Per club, in one transaction: lock the club, re-check it's still a guest and expired (a claim may have raced it), collect the session ids and public ids, then `$club->delete()`.
- After commit:
  - delete `podium/{session id}` and `dupr-exports/{club id}`
  - forget the public result, session and stats caches
  - set the `guest-gone:{slug}` tombstone for 7 days
  - broadcast `session.expired` on `play-session.{public_id}`
- **Existing gap fixed here:** `ClubService::delete` never removed podium files. Both paths use one cleanup helper.

## Claim

1. `GET /guest/claim/{club}` needs the cookie. A logged-out visitor gets `redirect()->guest(route('register'))`, and `intended` brings them back after register, verify or login.
2. The guest cookie is separate from the Laravel session, so it survives the session regeneration at login.
3. `POST` runs `GuestClubService::claim(club, user, token)`. It locks the user, then the club, and re-checks guest, not expired and the token. It runs `ClubPolicy::create` and the `max_owned_clubs` cap (moved into a shared helper with `ClubService`), attaches the owner, clears the guest columns, and sets `guest_claimed_at`, `current_club_id` and an optional name.

## Risks

- **The `Gate::before` whitelist** is the highest risk: a scoping bug would hand any session's board to anyone with a guest cookie.
- **The route regroup** touches the busiest staff pages, so normal-club regressions are possible.
- **No scheduler, no purge.**
- **Anonymous public pages** with arbitrary names stay up on the domain for up to 72 h.
- **Venues share one IP**, which affects the start limit (TrustProxies in P6.2).
