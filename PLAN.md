# PickleQ+ — Project Plan

A free, self-hosted Laravel app modelled on PickleQ's **Venue Pro** plan: open-play queue and court rotation, live TV and phone views, QR self check-in, stats, and **DUPR integration** (CSV export now, API sync later).

> **Progress is tracked in the [Checklist](#checklist) below.** Every completed item must be ticked in the same change that completes it (see `CLAUDE.md`).

---

## 1. Decisions

| Area | Decision |
|---|---|
| Stack | Latest Laravel · Livewire · Laravel Reverb (WebSockets) · Tailwind CSS · MySQL |
| Hosting | Decide later — build and run locally first. Reverb needs a long-running process (VPS, not shared hosting). |
| Tenancy | **Anyone can sign up** and create clubs. Each club has its own staff, roster, settings and DUPR club ID. |
| Players | **Stored per club** (not shared globally): name, DUPR ID, DUPR rating, stars. |
| Skill | DUPR rating → **1–6 stars** (bands below). Unrated / no DUPR → stars set manually. Bands editable per club. |
| Rotation | **Balanced** only in v1. Engine designed so more modes can plug in later. Phase 7 adds Mixed doubles, Skill courts, Winners stay and King/Queen of the Court, chosen per session (see Phase 7 rules). |
| Scoring | 1 game to 11, **side-out**. Stored per session as config so it can change later. |
| Check-in | Per-session QR code → **pick your name** (no PIN). New players can self-register. |
| v1 features | TV/kiosk display · QR self check-in · public live queue link · all-time stats & leaderboard |
| DUPR | **CSV export in v1.** API sync behind a feature flag, enabled once DUPR approves Partner API access. Matches where any player lacks a DUPR ID are **skipped with a warning**. |
| Fees | None. |
| Scaffold (Phase 0) | Laravel 13 with the official Livewire starter kit (`laravel/livewire-starter-kit:dev-main`: Livewire 4, Flux, Fortify auth with email verification, 2FA, passkeys). Livewire single-file components use the kit's `⚡` filename prefix. |
| Testing and lint | Pest 4. `tests/Unit` stays framework-free (for the rotation engine), and `tests/Feature` uses TestCase with RefreshDatabase. Tests run on SQLite in-memory, and a MySQL CI job is added in P1.9. Pint uses the `laravel` preset. Larastan runs at PHPStan **level 8**. Always run tests with `composer test`, which clears cached config. MySQL runs use `composer test:mysql`, which calls `vendor/bin/pest` directly. `php artisan test` unsets every var named in `.env`, so it silently drops the `DB_*` overrides and falls back to SQLite. The guard test `DatabaseDriverTest` (active when `REQUIRE_MYSQL=1`) asserts the mysql driver and InnoDB tables. CI uses MySQL **8.4 LTS**. |
| Database engine | The MySQL connection is forced to InnoDB in `config/database.php`, because the local server defaults to MyISAM. |
| Dev tooling | `laravel/pao` (dev-only) is kept from the kit. It compacts test and lint output for AI agents, and `PAO_DISABLE=1` gives full output. `laravel/chisel` was removed because it's only used at install time. |

### Star bands (default)

| DUPR rating | Stars |
|---|---|
| below 2.50 | ★1 |
| 2.50 – 2.99 | ★2 |
| 3.00 – 3.49 | ★3 |
| 3.50 – 3.99 | ★4 |
| 4.00 – 4.49 | ★5 |
| 4.50 and up | ★6 |
| No DUPR / NR | set manually (1–6) |

---

## 2. Data model

```
users ─< club_user (role: owner | staff) >─ clubs
users:            + current_club_id? (last club used; for redirect after login)
clubs:            name, slug, dupr_club_id, star_bands(json), default_courts,
                  late_arrival_policy(minimum|front|back), allow_concurrent_sessions
club_invitations: club_id, email, role, token_hash, invited_by, expires_at, accepted_at
players:          club_id, public_id, name (unique per club, P9), dupr_id?, dupr_rating?, stars, rating_source(manual|dupr), active,
                  self_registered_at?, self_registered_session_id?
play_sessions:    club_id, name, date, courts, scoring(json), status(draft|live|ended), public_id, tv_id, checkin_token?,
                  up_next_count, auto_fill, started_at, ended_at
session_players:  play_session_id, player_id, status(waiting|playing|break|left), checked_in_at,
                  games_played, games_credit, queued_at, last_finished_at      unique(play_session_id, player_id)
matches:          play_session_id, court_no?, status(staged|playing|done|void), team_a_score, team_b_score,
                  started_at, finished_at, dupr_eligible, dupr_exported_at, dupr_export_id?, dupr_synced_at, dupr_match_ref
match_players:    match_id, player_id, team(A|B), slot(1|2)
dupr_exports:     play_session_id, user_id? (null on user delete), match_count, file_path
```

Phase 7 additions (P7.4, see `docs/design/p7.4-rotation-modes.md`): `players.gender?` (man|woman), `play_sessions.rotation_mode` (default balanced) + `mode_settings?` (json), `matches.court_group?`, and `session_players.pending_court?` + `pending_from_match_id?` (P7.4d). `matches.court_no` is also set on court-bound staged matches.

`play_sessions` is named so to avoid clashing with Laravel's own `sessions` table.

**Naming (decided in Phase 0 review):**
- Model `PlaySession` (never `Session`, which clashes with the facade). Foreign keys are `play_session_id`, not `session_id`.
- Model `GameMatch` with `protected $table = 'matches'`. `match` is a reserved word in PHP 8, so a `Match` class is a parse error. Relation names can still read naturally, e.g. `$session->matches()`.

**Phase 1 rules (decided at Phase 1 start; change here first if needed):**
- **Club routing:** staff pages live under `/clubs/{club:slug}/...`. Slugs are globally unique, generated from the name, and the owner can edit them. The slugs `create` and `new` are reserved because they would collide with `/clubs/create`. Middleware checks membership. Non-members get **404**, so club existence isn't revealed. Club-owned data is always queried through the route-bound club (`$club->players()`), never through a request `club_id`.
- **Current club:** `users.current_club_id` stores the last club visited. `/dashboard` redirects there, or to "create your first club" if the user has none. The sidebar has a club switcher.
- **Roles:** `owner` can do everything, including club settings, star bands, invites, members and deleting the club. `staff` can manage players (and sessions later). A club can have several owners, but the last owner can't leave or be demoted. Any member can leave a club themselves. Creating a club makes you its owner. Only verified users can create clubs.
- **Invites:** owners invite by email and choose a role. The invite is a single-use token stored as a hash and expires in 7 days. The link works for a logged-in user whose email matches the invite. Anyone else logs in or registers first with that email, then accepts. Owners can resend or revoke pending invites.
- **DUPR IDs:** a player ID is 6 alphanumeric characters (e.g. `8DPLX8`). It's stored uppercase, is optional, and is unique within a club. A club's `dupr_club_id` is a 10-digit number. The DUPR rating is `decimal(5,3)` with a range of 2.000–8.000.
- **Stars:** `star_bands` is stored as 5 ascending thresholds, defaulting to `[2.50, 3.00, 3.50, 4.00, 4.50]`. A player with a rating defaults to `rating_source = dupr`, and stars are derived from the bands. Staff can switch any player to `manual` to override. Unrated players are always `manual`. Editing the bands recomputes stars for every `dupr`-sourced player in the club.
- **Players aren't hard-deleted.** Staff set them inactive, which keeps the match history for later phases.
- **Roster import:** UTF-8 CSV with a header row `name,dupr_id,dupr_rating`. The header is matched case-insensitively, and only `name` is required. Limits are 1 MB and 1,000 rows. A row matches an existing player by DUPR ID first, then by case-insensitive name. Matches are updated and new rows are created. **A name match never overwrites a different existing DUPR ID**, because it could be a different person with the same name. That row becomes an error. A blank cell never clears an existing value. Staff see a preview (create / update / skip / error per row) before confirming. *(P9: names are unique per club, so a name repeated in the file is always an error, and so is a DUPR-ID match whose new name belongs to another player.)*
- **Invite accepted by an existing member:** the invite is consumed, but the member's current role is **never changed**. Role changes only happen on the members page. Club and user names are Markdown-escaped in invite emails.
- **Import preview storage:** the preview is kept on the server in the cache, keyed by user, club and a random id, with a 30-minute TTL. It's never put in the Livewire snapshot. The file is read only up to the row limit, so oversized files are rejected early.
- **Invite revoke** deletes the invitation row. Invite throttling is enforced in `InvitationService`, so every caller (Livewire or HTTP) is covered.
- **Signup abuse (P1.8):** registration is limited to 5 POSTs per hour per IP. The registration form has a honeypot field. Email verification is required before any club access. A user can't **create** a club while already owning `pickleq.max_owned_clubs` clubs (default 5). The cap is checked only at creation, so being invited or promoted to owner is never blocked. We accept the workaround (promote a second verified account, then step down) because it's costly for spammers. Invites are limited to 20 per hour per club.

**Phase 2 rules (decided at Phase 2 start; change here first if needed):**
- **Who:** owners and staff can create, edit, start and end sessions and run the board. Sessions live under `/clubs/{club:slug}/sessions/...` and use the Phase 1 club scoping.
- **Lifecycle:** `draft → live → ended`. `ended` is final. Only drafts can be deleted. Players can be checked in during `draft` or `live`. Matches can be staged or started only while `live`. Ending a session is blocked while a match is `playing`. Staged matches are voided automatically on end.
- **Concurrent sessions:** club setting `allow_concurrent_sessions` (owner, checkbox, **default off**). When it's off, starting a session is blocked while another session in the club is live. When it's on, a player can still be checked into only one live session at a time.
- **Session settings:** `courts` 1–50, defaulting to the club's `default_courts`. Courts can change while live, but a court with a playing match can't be removed. `up_next_count` 1–3, **default 1**. `auto_fill` **default off**. `scoring` defaults to `{"type":"side_out","games":1,"to":11,"win_by":2}`. `to` may be 11, 15 or 21, and `win_by` 1 or 2.
- **Valid score:** no ties. The winner has at least `to` points and wins by at least `win_by`. If the winner has more than `to`, the margin must be exactly `win_by`. So for to 11, win by 2: 11–9 and 12–10 are valid, while 11–10, 13–10 and 10–8 aren't.
- **Queue priority inputs:** `effective_games = games_played + games_credit`, then `queued_at`, the time the wait clock started (check-in, return from break, or match finish). `games_played` stays the real count shown on the board. `games_credit` exists only for queue priority.
- **Late arrival / return from break:** club setting `late_arrival_policy` (owner):
  - `minimum` (**default**): credit up to the lowest `effective_games` among the other waiting and playing players.
  - `front`: no credit.
  - `back`: credit up to the highest `effective_games`.

  Credit only ever raises. It never lowers an existing credit. With nobody else active, the credit is 0.
- **Staging:** the engine is called once per open Up Next slot. Players already in a staged or playing match are excluded. Staged matches have no court. Starting one assigns the lowest free court number. While the session is live, empty Up Next slots are **always** refilled after every change, whether or not `auto_fill` is on. `auto_fill` only controls starting: when it's on, every free court immediately starts the oldest staged match. Lowering `up_next_count` voids the newest surplus staged matches. Staff can also **re-roll** a staged match. That voids it and stages the lowest-cost group that **isn't the exact same four**: the engine is run once with each old player left out, and the best result is taken. The same four come back only if no other group is possible.
- **Leaving a staged match:** if a staged player checks out or goes on break, the staged match is voided and the slot is re-staged. Checking out or breaking a **playing** player is blocked. Swap them out first.
- **Swap / remove (P2.7):** you can swap a player in a staged or playing match for a waiting player who isn't in another match. Removing a player fills the slot with the waiting player the engine ranks best for it. The removed player goes back to `waiting` (or `break`/`left` if chosen). No game is counted for them.
- **Void:** staged → players freed. Playing → players back to `waiting`, no games counted, court freed. Done → `games_played` is decremented for its players, and the match is excluded from stats. A match with `dupr_exported_at` set can't be voided.
- **Finish / undo / edit:** finishing records the score, increments `games_played`, sets `last_finished_at` and `queued_at`, and frees the court. **Undo last result** reverts the session's most recent `done` match to `playing`. It's allowed only if that court is free and none of its 4 players is in a playing match, on a break, or has left. If any of them has already been re-staged into Up Next, that staged match is voided and Up Next refilled afterwards. With `auto_fill` on, the court is usually taken at once, so staff use edit score. Otherwise staff use **edit score**, which is allowed on any `done` match until it's DUPR-exported.
- **Repeat history** for the engine counts the session's non-void matches (staged, playing and done).
- **Concurrency:** every state change to a session (check-in, staging, start, finish, swap, void, undo, settings) runs in one DB transaction that first locks the `play_sessions` row (`lockForUpdate`). Starting a session also locks the club row. Checking a player into a live session also locks the player row. After commit, services dispatch domain events. They become broadcast events in P3.1.
- **Wait estimate (P2.8):** a pure `WaitEstimator`. Inputs are queue position, courts, staged count, elapsed time of playing matches, and the average duration of the session's last 10 done matches (default 15 min from config). The output is minutes.
- **Livewire structure:** Phase 2 components that contain logic (session pages and the board) are **class-based components in `app/Livewire`**, so Larastan level 8 analyses them. Thin pages may stay as `⚡` single-file components.

**Phase 3 rules (decided at Phase 3 start; change here first if needed):**
- **No video.** "Live views" means live-updating screens of courts and the queue. There's no streaming or recording of play.
- **Public link:** every play session gets a `public_id`. It's a random, unguessable 12-character lowercase alphanumeric string, unique, set on create, and existing rows are backfilled. It never changes. The public queue URL is `/c/{club:slug}/s/{public_id}`. It's meant to be shared, e.g. in group chats. **The TV URL is separate and secret:** `/c/{club:slug}/tv/{tv_id}`, where `play_sessions.tv_id` is a random 32-character string set on create. It's shown only to staff on the board, and staff can **reset** it. The reason is that the TV shows the live check-in QR, so a TV URL derived from the public link would let anyone with the shared link check in from home and search the roster's full names. *(Changed after the P3 UI review.)* Someone photographing the QR at the venue is an accepted risk. Staff can regenerate the QR, and it expires when the session ends. If the session doesn't belong to that club, the result is 404. Sequential ids are never exposed publicly. **This includes players:** `players.public_id` (random, 12 chars, unique) is the only player handle on public pages, in the public read model, in check-in search results and in check-in submits. It's resolved within the session's club. *(Added after P3 review: raw ids let anyone with the QR check in any player by counting through ids.)* Public pages are read-only, need no login, and show players' names as entered. Draft shows "not started yet", and ended shows "session ended".
- **Broadcasting:** there's one **public** channel per session, `play-session.{public_id}`. The `PlaySessionChanged` domain event is turned into a broadcast `session.updated`. Its payload has **no player data** (just enough to trigger a refresh), and clients re-render from the server. The broadcast is queued (`ShouldBroadcast`), after commit, and **at most once per session per request/job**, even when one action fires the domain event several times. A Reverb or queue outage must never fail an organizer action. The staff board listens on the same channel, so several staff devices stay in sync.
- **Check-in token (P3.5):** `checkin_token` is a random 40-character string, stored plain (it has to be rendered as a QR at any time). It's issued when a session is created and works while the session is `draft` or `live`. It's cleared when the session ends. Staff can **regenerate** it, which kills the old QR at once. The QR encodes `/checkin/{token}` and is rendered as SVG with `bacon/bacon-qr-code`, which is already installed via Fortify, so no new package is needed. The QR is shown on the TV display and the organizer board.
- **One name field (user decision, Phase 9; replaces the nickname rule):** a player has a single required `name` (max 120). The player types whatever they want to be called: a full name or a nickname ("Ace"). It's shown **exactly as entered** everywhere, including public and TV pages. There's no "First L." shortening. `name` is **unique per club, case-insensitive** (unique index on `(club_id, name)`; every write path catches the violation). `players.nickname` is dropped. Existing full names are kept and nicknames are discarded. Before the index is added, the migration renames any existing duplicate names within a club by adding " 2", " 3"… to the later rows (by id). *Previously (P3): a separate nickname, shown publicly, with a "First L." fallback.*
- **Public queue page (P3.3):** shows current courts, Up Next and the waiting list, with positions and estimated waits from `WaitEstimator`. A visitor can tap **"This is me"** to pick themselves from the checked-in list. That choice is stored only in the browser (localStorage per session) and is set automatically after a self check-in on that device. The server never shows who is watching.
- **"You're up next" (P3.4, user decision):** page-open only, so there's no Web Push or service worker. When the "me" player enters a staged match, or a staged match with them starts on a court, the page shows a banner, vibrates (where supported) and fires a browser `Notification` if permission was granted. Permission is requested only from a user tap. This works while the page or tab is open, including in the background. It needs HTTPS in production (P6.2).
- **Self check-in (P3.6):** `/checkin/{token}` is valid only while the token's session is draft or live. Otherwise it shows a friendly "check-in closed" page. Players **search** by name, with a minimum of 2 characters and at most 10 active players returned. Picking a name checks the player in through `CheckInService`, so the usual late-arrival credit and single-live-session rules apply. If the player is already checked in, the page says so. Search results report each player's session status (not checked in / waiting / playing / on break). Picking a player who is on break returns them from break, which is the same as at the desk. Every self check-in call carries the **token** and re-checks it inside the session lock, so a page left open with a regenerated (old) QR stops working at once.
- **Self-register (P3.6, user decision; nickname removed in P9):** the form takes **name (required)**, an optional DUPR ID, and **self-rated stars 1–6**. Each level has a short plain-language description. The player is created with `rating_source = manual`, with `self_registered_at` and `self_registered_session_id` set, then checked in. "Self-registered in this session" means `self_registered_session_id` equals that session. A DUPR ID or name already in the club is rejected with "you're already on the roster, search for your name". The board shows a **"new"** badge on players self-registered in the current session, so staff can check their level.
- **Abuse guards (P3.7):** the limits are per IP and per session:
  - search: 60 per minute
  - check-in or self-register submits: 10 per minute
  - self-registrations: 5 per hour per IP, and at most 100 per session. Only **successful** registrations count toward the 5. Rejected attempts are capped by the submit limit. This is because a whole venue often shares one IP.

  Staff can **remove** a bogus check-in from the board. Removing deletes the `session_players` row, but only if the player has no staged, playing or done match in the session. Otherwise staff use check-out. If the removed player was self-registered in that session and has no matches anywhere, the player record is deleted too. This is the one exception to "players aren't hard-deleted", because these are spam records with no history.

**Phase 5 rules (decided at Phase 5 start; change here first if needed):**
- **What counts:** stats use only matches with `status = done` and both scores set. Void, staged and playing matches never count. The source of truth is `matches`/`match_players`, not `session_players.games_played`. The winner is the team with the higher score (valid scores have no ties). Per player: `played`, `wins`, `losses`, `win %` (wins ÷ played), `points for`, `points against`, `point diff`. DUPR status doesn't affect stats.
- **Visibility (user decision):** *(Superseded 2026-10-09 by the Phase 11 rules: the `public_stats` switch is removed and the public stats pages are always on. The text below is kept as history.)* Every stats page is available to club members (owners and staff), with the usual Phase 1 club scoping. New club setting **`public_stats`** (owner, checkbox, **default off**). When it's on:
  - a public leaderboard at `/c/{club:slug}/stats`
  - the public page of an **ended** session (`/c/{club:slug}/s/{public_id}`) shows its final standings and match log instead of only "session ended"

  When it's off, `/c/{club:slug}/stats` is a 404 and the ended page is unchanged. Public pages show the player's `name` as entered (P9), never DUPR IDs or any id except `players.public_id`. **Player profiles are always staff-only.**
- **Session standings (P5.1):** for one session, rank by wins desc → win % desc → point diff desc → name asc. There's no minimum number of games. Rows: rank, player, played, W, L, win %, point diff. On staff pages, a live session shows "standings so far". Publicly, standings appear only once the session has ended. *(Changed 2026-10-08, user decision, P10: each player's **live session wins** are shown on the board and on the public queue while the session is live, whatever `public_stats` says. Full standings, W–L, win % and point diff stay staff-only until the session ends, as before.)*
- **Leaderboard (P5.2, user decision):** ranked by **win %** desc → wins desc → point diff desc. Only players with at least **`leaderboard_min_games`** games in the period get a rank. That's a new club setting (owner, 1–100, **default 10**). Everyone else is listed below the ranked players as "not ranked yet", sorted by played desc and then name. Players tied on all three keys share a rank (1, 2, 2, 4), and the display order inside a tie is by name. Win % is compared exactly (cross-multiplied, not as floats) and shown as a whole-number percentage. Inactive players are left out of the leaderboard. Their profiles and history stay available to staff.
- **Periods (user decision):** `all_time` (default), `this_month`, `last_30_days` and `this_year`, picked from a dropdown. A period filters on the session's `date` in the app timezone. `last_30_days` means today and the 29 days before it. The same ranking rules and minimum apply to every period. The leaderboard and profile both take the period. The public leaderboard has the same dropdown.
- **Player profile (P5.3):** `/clubs/{club:slug}/players/{player}` (staff only, scoped binding). It shows:
  - the header (name, DUPR ID and rating, stars, active)
  - the record for the chosen period: played, W–L, win %, point diff, sessions attended, last played
  - **partners**: games together, wins together and win % together, sorted by games desc and then name
  - **opponents**: games against and the player's W–L against them
  - **match history**, newest first and paginated (20 per page): date, a link to the session, partner, opponents, score from the player's side, and W/L

  Roster rows and staff leaderboard rows link to the profile.
- **Session history and match log (P5.4):** the staff sessions list gets pagination (20 per page) and a status filter (all / draft / live / ended). Live and drafts are still listed first. Ended rows show the match count and player count and link to the results page. Staff results page: `/clubs/{club:slug}/sessions/{session}/results`. It shows the standings plus the **match log**: every done match in finish order with court, finish time, duration, teams and score. Void matches are listed greyed out and marked "void" (staff only). The public ended page lists done matches only.
- **Architecture:**
  - The ranking (sort, ties, minimum games, shared ranks, exact win % comparison) is a pure class in `app/Domain/Stats`. It takes plain rows and has unit tests.
  - Queries live in a `StatsService` in `app/Services`. The leaderboard counts are aggregated in SQL (`GROUP BY player_id`), and the query has to work on both SQLite and MySQL. Profile partner and opponent tables are aggregated from that one player's matches.
  - Add indexes only when a stats query needs them.
  - The public leaderboard is cached for 60 seconds per club and period, because it's an unauthenticated aggregate query. Staff pages aren't cached.
  - Stats pages are read-only and don't listen on the live channel. A staff page shows fresh data when reloaded.
- **Details settled in P5 backend:**
  - "Sessions attended" counts the sessions where the player has at least one counted match, not check-ins.
  - "Last played" is the date of the latest such session.
  - Players with no counted games in the period don't appear on the leaderboard at all, not even as unranked.
  - The public leaderboard cache isn't flushed when the settings change, so it can be up to 60 seconds stale.
  - The sessions list shows `players_count` (everyone ever checked in, including those who left).

**Phase 7 rules (decided at P7.3/P7.4 start, 2026-10-07; change here first if needed):**
- **P7.3 is blocked on P7.1 (user decision).** The public DUPR API (`api.dupr.gg/api-explorer`, group `public`, server `api.dupr.com`) has only 4 endpoints, all of which need a bearer token: `/subscription/active`, `/user/club/membership`, `/public/user/info` (the token holder only) and `/auth/{version}/refresh`. None of them looks up another player's rating, so there's no anonymous rating lookup. Nothing gets built until DUPR approves Partner API access. Once it does, fetch triggers are **on DUPR ID save** (queued job: player form, roster import, self-register) and a **nightly refresh** of every player with a DUPR ID. There's no manual refresh button. A fetched rating updates `dupr_rating`. As before, stars are recalculated only for `rating_source = dupr`. Use the **doubles** rating.
- **Rotation modes (P7.4):** `balanced` (the default and the v1 behaviour), `mixed`, `skill_courts`, `winners_stay` and `king_of_court`. The mode is a per-session setting.
- **Changing the mode while live (user decision):** allowed. Switching voids staged Up Next matches, and playing matches carry on. The new mode takes over as courts free up. *(Settled in the P7.4a review.)* A settings change voids Up Next only if the active mode stages from that setting (`skill_groups` in skill courts). A `winners_stay_max_wins` change applies at the next finish. `PlaySessionService::changeVoidsUpNext()` decides this, and the form asks for confirmation only when it returns true. Modes are offered only if listed in `pickleq.rotation_modes_enabled`. Each P7.4 item adds its mode there when it's done.
- **Mixed doubles:**
  - Every team is 1 man + 1 woman, and those teams are balanced as usual.
  - **If the waiting players don't include 2 men and 2 women, the Up Next slot waits** (user decision). There's no fallback to a non-mixed match.
  - Players need `players.gender` (nullable, `man`/`woman`). A player with no gender is never placed in a mixed session. The board flags them.
  - Gender can be set in 3 places (user decision): the staff player form (and a roster import column), an optional question at self-register, and self check-in, where a player with no gender may set it but never overwrite it.
- **Skill courts:** staff split the courts into groups, and each group has a star range (e.g. courts 1–2 for ★4–6, the rest for ★1–3). Every player belongs to the group that matches their stars. Each group has its own balanced queue and Up Next slots. A staged match is started on the lowest free court in its group.
  - *Settled in P7.4c and its review:*
    - **Groups need:** at least 2 courts and at least 2 groups. The court ranges are contiguous and cover every court exactly. The star ranges cover ★1–6 exactly, with no overlap.
    - **Default groups:** the top half of the courts (1..⌈n/2⌉) is ★4–6 and the rest is ★1–3. Court 1 is the top court. A switch to skill courts with no groups given uses the default. Changing the number of courts resizes the last group, and is rejected if a group would be left with no courts.
    - **Up Next per group:** `up_next_count` applies to each group. Lowering it voids the newest surplus matches.
    - **Group membership** comes from the player's stars at the time of staging. A star change takes effect at the next staging.
    - **Stale or invalid groups:** a staged match with a stale or invalid group is voided on refill, and can never start. A playing match's group is worked out from its court.
    - **Swap and remove:** swap may cross groups as a staff override. Remove picks from the group.
    - **Public pages** show group labels ("Courts 1–2") and positions within the group, never stars.
- **Winners stay:** the winning team stays on the court, and the losers go back to the queue. The next 2 challengers come from the queue, picked by the engine to balance against the winners. After **`winners_stay_max_wins`** wins in a row (a per-session setting, 1–5, **default 2**; user decision), all 4 players go back to the queue. A court whose last match didn't leave a winning team on it is filled with an ordinary balanced match.
- **King/Queen of the Court, as a rolling ladder (user decision):** court 1 is the King court. When a court finishes, its winners move up a court and its losers move down a court. Court 1's winners stay on court 1, bottom-court losers go back to the queue, and the queue feeds the bottom court. Each court collects 4 incoming players. When it has all 4, a match is staged on that court, and partners are split (one player from each incoming pair per team). No court waits for every other court to finish. The first fill puts the highest-star players on court 1.
- **Rules settled in the P7.4 design** (user decisions are marked; full design in `docs/design/p7.4-rotation-modes.md`):
  - In winners stay and King of the Court, Up Next is each court's bound staged match, and `up_next_count` is hidden and ignored.
  - **Winners stay, broken team (user decision):** if one of the staying winners goes on break or checks out, the court resets. The other winner goes back to the queue, and the court gets a balanced match.
  - **Skill courts are strict (user decision):** courts never take another group's match, even when they're idle.
  - **King of the Court has no win cap on court 1 (user decision).** The winners split every game anyway.
  - Voiding a playing match in winners stay or King of the Court costs those players their court spot. They go to the plain queue.
  - **King of the Court ladder size:** with fewer than 4 × courts players, the ladder shrinks to B = min(courts, ⌊(waiting + playing) / 4⌋), so nobody waits on a court that can't be reached.
  - A King of the Court pool with more than 4 players stages the 4 earliest arrivals, and the rest go to the queue.
  - With fewer than 2 challengers, the winners-stay winners wait and the court sits idle.

**Phase 8 rules: UI/UX refresh (decided 2026-10-08; change here first if needed):**
- **Goal (user request):** a modern, clean, easy-to-navigate UI across the app, plus a real marketing landing page at `/`. This is visual and UX work only. No domain logic, routes, permissions or data changes, except a route or view needed for the landing page.
- **Theme (user decision):** **light mode by default** for everyone, including first visits, regardless of the OS preference. Dark mode stays available through a sun/moon toggle that is visible on the landing page, auth pages, the app shell and the public queue, check-in and leaderboard pages. The choice is stored in the browser via Flux's appearance store, so it carries across pages. Settings → Appearance keeps Light / Dark / System, with Light as the default.
- **TV display stays dark** whatever the theme setting, because it's a venue screen read from about 10 m (P3.2). It gets the same brand styling.
- **Brand (orchestrator default, change if the user wants):** a fresh pickleball-green accent on a neutral base, with rounded cards, soft borders and shadows, and clear type hierarchy. Fonts are loaded the way the kit already does it, with no new npm or composer packages. Illustrations are inline SVG or CSS, so there are no external image assets.
- **Navigation:** every staff page has a clear page header (title, short description, primary action). The sidebar groups club pages, shows the active page and keeps the club switcher. Lists have helpful empty states that point to the next step. Every page works on a phone.
- **Tests:** existing `data-test` hooks and the strings tests assert on are kept, unless a test is updated on purpose along with the copy. The design system is written down in `docs/design/ui-style.md` so later pages stay consistent.

**Phase 11 rules: shareable results (decided 2026-10-09; change here first if needed):**
- **Goal (user request):** a results page that looks good enough to post on social media, an animated podium GIF of the top 3, and public links where anyone can see a session's results, browse the club's earlier sessions and see the all-time leaderboard.
- **Visibility (user decision): everything is always public.** The `public_stats` club setting is removed (migration drops `clubs.public_stats`; the settings checkbox goes). `/c/{club}/stats`, the ended-session results at `/c/{club}/s/{public_id}` and the new `/c/{club}/sessions` list work for every club. This replaces the Phase 5 visibility rule. The privacy rules don't change: public pages show `name` as entered and `players.public_id` only, never DUPR IDs, ratings, stars or gender. Player profiles stay staff-only. Draft sessions are never listed publicly.
- **Search engines (orchestrator default):** public results, sessions and leaderboard pages send `<meta name="robots" content="noindex">`, so players' names don't show up in search results. Social link previews still work, because preview crawlers ignore it.
- **Share link:** the share link for a session's results is its existing public link, `/c/{club}/s/{public_id}`, the same link players already have in the group chat. While the session is live it's the public queue. Once it has ended it's the results page. Standings are still public only after the session ends (Phase 5).
- **Results page design (staff and public share it):** built from shared Blade components so both pages look the same.
  - **Hero:** club name, session name, date and headline numbers: matches played, players, total points and courts.
  - **Podium:** the first 3 rows of the session standings, in standings order. Players tied on rank share the rank and medal. Each spot shows the player's initials, name, W–L, win % and point diff. Gold, silver and bronze styling, an entrance animation and confetti, all in CSS, and no motion when `prefers-reduced-motion` is set. Fewer than 3 ranked players gives a smaller podium. No counted matches gives an empty state and no podium.
  - **Highlights** (each one hidden when it doesn't apply): **Most games** (most played, ties go to the name that sorts first), **Best point diff** (highest diff, only if > 0, same tie rule), **Closest match** (smallest winning margin, ties go to the earliest finished) and **Biggest win** (largest margin, same tie rule). Matches only count if they count for stats (Phase 5).
  - Then the full standings and the match log, as in Phase 5. The public page lists done matches only, and the staff page keeps void rows and profile links.
  - **Share panel** (staff page and public page, only for ended sessions): copy link, native share sheet where supported, QR of the share link (`CheckInQrService::svgForUrl`), Facebook / X / WhatsApp / Messenger share links (plain URLs, no SDKs), **Download GIF** and **Download image**. On a live session the staff page says sharing opens once the session ends.
  - **Link previews:** the ended results page sets Open Graph and Twitter card tags: title "{session} · {club} results", a description with the top 3 ("🥇 A · 🥈 B · 🥉 C"), and `og:image` = the PNG share image.
- **Podium images:** `GET /c/{club}/s/{public_id}/podium.gif` and `/podium.png`. They're only for ended sessions with at least one counted match, and are 404 otherwise.
  - They're drawn in pure PHP with GD (FreeType) and a bundled OFL font (Plus Jakarta Sans, matching the UI) in `resources/fonts/`. **No new packages.** A small GIF89a encoder stitches the GD frames into one looping animated GIF.
  - **Layout:** square. The club and session name and date at the top, the podium blocks rising, the top 3 names and W–L dropping in, confetti, and a "PickleQ+" footer. The GIF is 800×800, plays the animation and then holds the final frame for about 3 s before it loops. The PNG is the final frame at 1080×1080 and is used as `og:image` and for "Download image".
  - Images are cached on the server, keyed by the session, a hash of the podium data and a renderer version, so a score edit after the session ends gives a fresh image. Responses send `Cache-Control: public, max-age=300` and an ETag. *(Changed at the P11 review.)* The image routes are **stateless**: no session, CSRF or cookie middleware, so a publicly cached response never carries a `Set-Cookie`. The podium comes from the cached public results read model, so a request doesn't run a stats query. Only **renders** (disk-cache misses) are limited, to 30 per minute per IP, with a lock so the same image is never drawn twice at once. Cached hits and 304s get a generous route limit (300 per minute per IP), because a whole venue often shares one IP and every results page view loads the GIF.
  - Names the font can't draw (emoji, some non-Latin scripts) show as boxes. That's accepted for now.
- **Public club navigation:** a shared public club header on the results, sessions and leaderboard pages with the club name and tabs **Sessions** and **Leaderboard**. A results page has **← Previous / Next →** links to the club's neighbouring ended sessions (ordered by `date`, then `id`) and a link to all sessions.
- **Public past sessions (`/c/{club}/sessions`):** a banner for the club's live session if there is one (it links to the public queue). Then the ended sessions, newest first (`date` desc, `id` desc), 20 per page. Each row shows name, date, match count, player count (players with at least one counted game) and the session's #1 player. The query must not run once per session. The list is cached for 60 seconds per club and page, like the public leaderboard. `/c/{club}` redirects to it.
- **Public read models** are cached for 60 seconds (as in Phase 5), so a staff edit after the end can take up to a minute to show.
- **Screenshot-ready tables (user request 2026-10-09, P11.6):** the standings table (`x-stats.table`: session results and both leaderboards) and the match log (`x-stats.match-log`) are restyled so a phone screenshot looks good enough to post. This is visual only. The data, ranking rules, ordering and privacy rules don't change.
  - **Standings:** the top 3 ranked rows get gold, silver and bronze medal badges and a soft tinted row. Players tied on rank share the same medal. Each player has an initials avatar with a colour derived from the name. Win % is shown as a number with a thin progress bar. Point diff is a green (+), red (−) or neutral (0) pill. W and L are coloured. Numbers use tabular figures. On a phone (≤ 640 px) nothing scrolls sideways: Played, W and L fold into one "W–L · N played" line under the name.
  - **Unranked list (leaderboard):** no rank column. Each row shows progress toward the minimum, e.g. "6 / 10 games", so players can see how close they are.
  - **Match log:** each match is a compact row or card with a court badge, the finish time, both teams on separate lines, and a score pill. The winning team is bold with a small "Won" marker and the losing side is muted. No winner is marked when the scores are missing or tied (Phase 5 rules). Void rows stay greyed out and struck through (staff only). Durations of an hour or more read "1 h 5 min". When a match finished on a different day from the session date, the time shows the date too ("Oct 8 · 13:21"), so the finish order doesn't look scrambled.
  - **Branding:** both tables sit in rounded cards with a subtle brand-green header band. On the results page, a small footer line inside the card ("{club} · {session} · {date} · PickleQ+") keeps a cropped screenshot recognisable. Light and dark mode both work. The TV display is unchanged.
- **Details settled in P11:**
  - **Robots:** the public layout already sends `noindex, nofollow` on every public page, so no extra tag was added.
  - **Initials:** the first letter of the first and last words of the name, or `?` when the name is empty.
  - **Name ties:** "first name" ties are broken case-insensitively.
  - **Messenger link:** shown on phones only, because `fb-messenger://` does nothing on a desktop.
  - **GIF/PNG controls:** hidden when the session has no counted match.
  - **Hero:** always uses the dark brand gradient, in both themes, so screenshots look the same.
  - **Image cache:** `podium/{session id}/{hash}-v{RENDERER_VERSION}.{ext}` on the `local` disk. Older files for that session are deleted when a new one is written. The ETag is computed before rendering, so a 304 never renders.
  - **Image layout:** with 2 entries, 2nd is on the left and 1st on the right. Confetti never covers text.
  - **Paging:** a `?page=` beyond the last page shows the last page.

---

## 3. Balanced rotation engine

1. **Priority order** of the waiting list: fewest games played → longest wait since last game.
2. **Candidates:** take the top 6–8 waiting players; evaluate every 4-player group × its 3 possible team splits.
3. **Cost (lowest wins):**
   `|stars(A) − stars(B)| × w1 + repeat partners × w2 + repeat opponents × w3 + skipped-priority × w4`
4. **Stage** the winner as "Up Next"; when a court frees, the organizer taps to start (or auto-fill).

Pure PHP service — no DB or UI dependency — so it is fully unit-testable. Weights live in config.

**Details (decided at Phase 2 start):**
- **Location:** `app/Domain/Rotation`. Inputs are plain value objects:
  - candidates: id, stars, effective_games, queued_at
  - session pair history: partner counts and opponent counts per player pair
  - weights and window size

  The output is the chosen 4 as teams A/B with a cost breakdown, or `null` if fewer than 4 candidates.
- **Priority sort:** `effective_games` asc → `queued_at` asc → player id asc. The sort is fully deterministic, with no randomness.
- **Window:** the top `rotation.window` players (config, default 8, min 4). C(8,4) × 3 = 210 evaluations.
- **Cost terms:**
  - `stars(X)` is the sum of team X's stars.
  - The repeat partner term sums the prior partner counts of both pairs.
  - The repeat opponent term sums the prior opponent counts of the 4 cross pairs.
  - `skipped-priority = Σ(priority rank of the chosen 4) − (0+1+2+3)`.
- **Ties:** lowest skipped-priority wins, then the lexicographically smallest sorted id list.
- **Config:** weights and the window go in `config/pickleq.php` under `rotation`. Defaults chosen in P2.3:

  | Key | Default | Why |
  |---|---|---|
  | `star_balance` | 3 | One star of team-sum imbalance outweighs skipping one queue rank. |
  | `repeat_partner` | 4 | A repeat partner is worse than a repeat opponent or one star of imbalance. |
  | `repeat_opponent` | 1.5 | A mild nudge, because opponents repeat naturally in small pools. |
  | `skipped_priority` | 2 | Skipping the front player (4 ranks, 8 points) needs more than 2 stars of imbalance to pay off. Raise it for a stricter queue. |
  | `window` | 8 | |
  | `avg_match_minutes` | 15 | Fallback for wait estimates. |

- **API:** `BalancedRotationEngine::pickMatch()` and `pickReplacement()` (used by P2.7 remove). The tie order after cost is lowest skipped-priority, then the smallest sorted id list, then the split order. Costs are compared with a 1e-9 epsilon.

---

## 4. DUPR integration

- **CSV export (v1):** match DUPR's official club template header row **verbatim**. The templates are committed in `docs/dupr/`. **One row per match** (not per game). Each row has slots A1/A2/B1/B2 with names and DUPR IDs, and up to 5 games in the `teamAGameN`/`teamBGameN` columns. *(Corrected at Phase 4 start: it used to say "one row per game".)*
- **Export page:** shows eligible count, skipped matches and which players are missing a DUPR ID. Exporting stamps `dupr_exported_at` so nothing is uploaded twice.
- **Abstraction:** `DuprPublisher` interface → `CsvPublisher` (now) and `PartnerApiPublisher` (later). Enabling sync is a config/flag change, not a rewrite.
### Phase 4 rules (decided at Phase 4 start; change here first if needed)

- **Template (user decision):** the app only creates doubles matches, so the exporter copies the header of **`docs/dupr/doubles-match-import.csv`** byte for byte. `docs/dupr/single-match-import.csv` is kept for reference only. It has the same 27 columns, but `location,scoreType` come after the game columns instead. The golden-file test (P4.6) reads the header from the committed doubles template, so it's never typed out in code or tests.
- **Row mapping:**

  | Column | Value |
  |---|---|
  | `matchType` | `D` |
  | `event` | session name |
  | `date` | session date, `YYYY-MM-DD` |
  | `playerA1`… `playerB2` | the player's `name` as entered (P9: this may be a nickname). A1 = team A slot 1, A2 = team A slot 2, B1/B2 likewise for team B |
  | `player…DuprId` | the player's `dupr_id` (uppercase) |
  | `player…ExternalId` | blank (user decision) |
  | `location` | club name (user decision) |
  | `scoreType` | `SIDEOUT` (`scoring.type = side_out`, the only type in v1) |
  | `teamAGame1` / `teamBGame1` | `team_a_score` / `team_b_score` |
  | `teamAGame2`–`5` / `teamBGame2`–`5` | blank (v1 matches are 1 game) |
- **CSV format:** UTF-8 without a BOM, `,` delimiter, LF line endings, with a trailing newline after the last row. A field is quoted **only** when it contains a comma, a double quote, CR or LF, and inner quotes are doubled. This is the minimal quoting the samples use. PHP's `fputcsv` also quotes fields that contain spaces, so it isn't used as-is. Names are written as entered.
- **Eligibility (P4.2):** a match is eligible when `status = done`, `dupr_eligible = true`, `dupr_exported_at` is null, both scores are set, and all 4 players have a DUPR ID. Every other `done` match is listed as **skipped** with a reason: missing DUPR ID(s) (the players are named), already exported, marked not eligible, or **incomplete** (a missing score or fewer than 4 players; added during P4.2). When several reasons apply, the first in this order wins: already exported, not eligible, incomplete, missing DUPR ID. Void, staged and playing matches aren't listed at all.
- **When:** exporting is allowed only once a session has **ended**. That way the set of matches is final, and nothing on the live board races the export. The page itself can be opened for any session, and a draft or live session shows "end the session to export".
- **Who:** owners and staff, with the usual Phase 1 club scoping. The session is resolved through the route-bound club.
- **Export (P4.5):** one DB transaction locks the `play_sessions` row (`lockForUpdate`), works out the eligible matches again inside the lock, builds the CSV, writes it to the private `local` disk at `dupr-exports/{club_id}/{dupr_export_id}.csv`, creates the `dupr_exports` row, and stamps `dupr_exported_at` and `dupr_export_id` on every exported match. If nothing is eligible, the export is refused and no file or row is created. Exported matches can't be voided, undone or have their score edited. Those guards already exist in `MatchService`.
- **History and re-download:** the page lists the session's past exports (date, who, match count), each with a download link. The link is a staff-only route that streams the stored file under the filename `dupr-{club-slug}-{session-date}-{export-id}.csv`. It returns 404 for another club's export or a missing file. Files are never public.
- **Abstraction:** `DuprPublisher::publish(PlaySession, Collection<GameMatch>, User): DuprExport`. `CsvPublisher` implements it. The CSV itself is built by a pure row/CSV builder that takes plain data, so it's unit-testable without the DB. Eligibility and locking live in a service, not in the publisher.

- **API (later):** use the [`Info-Esportes/dupr-partner-api`](https://github.com/Info-Esportes/dupr-partner-api) package (UAT `uat.mydupr.com`, prod `api.dupr.com`). Also enables automatic rating lookup → stars.

### Applying for DUPR Partner API access

There's no self-service signup; it's done by request.
1. Create a DUPR club (gives you a club ID).
2. Email `support@mydupr.com` (or your club account manager): what the app does, your own clubs only / non-commercial, club ID, expected volume, needed features (submit matches, look up players by DUPR ID).
3. If approved, receive UAT client key + secret (may require a partner agreement).
4. Build and test against UAT.
5. Request production credentials (they may ask for a demo).

---

## 5. Screens

| Screen | Route (indicative) | Who |
|---|---|---|
| Organizer board | `/clubs/{club}/sessions/{session}` | Staff |
| TV display | `/c/{club}/tv/{tv_id}` (secret link from the board) | Venue screen, read-only |
| Public queue | `/c/{club}/s/{session}` | Players |
| QR check-in | `/checkin/{token}` | Players |
| Roster | `/clubs/{club}/players` | Staff |
| Stats & leaderboard | `/clubs/{club}/stats` | Staff |
| Public leaderboard | `/c/{club}/stats` (always public since P11) | Public |
| Public session results (ended session) | `/c/{club}/s/{public_id}` | Public |
| Public past sessions | `/c/{club}/sessions` (`/c/{club}` redirects here) | Public |
| Podium GIF / share image | `/c/{club}/s/{public_id}/podium.gif` · `/podium.png` | Public |
| Session results & match log | `/clubs/{club}/sessions/{session}/results` | Staff |
| Player profile | `/clubs/{club}/players/{player}` | Staff |
| DUPR export | `/clubs/{club}/sessions/{session}/dupr` | Staff |
| Club settings | `/clubs/{club}/settings` | Owner |

---

## Checklist

Legend: `[ ]` todo · `[x]` done. Each item has an ID (e.g. `P2.3`) — reference it in commits and task assignments.

### Phase 0 — Project setup
- [x] **P0.1** Scaffold Laravel app (latest) with Livewire starter kit, Tailwind, MySQL config
- [x] **P0.2** Install and configure Laravel Reverb + Echo (local)
- [x] **P0.3** Configure Pest, Laravel Pint, Larastan; add `composer test` / `composer lint` scripts
- [x] **P0.4** Set up `.env.example`, README dev-setup section, GitHub Actions CI (lint + tests). *Split during review:*
  - [x] **P0.4a** `.env.example`, README dev setup, `.github/workflows/ci.yml` written, reviewed, and passing locally
  - [x] **P0.4b** *(verified: CI run on `main` @ `5ab0dcc` succeeded, 2026-10-05)* First green GitHub Actions run on the pushed branch. This also confirms the hand-patched `package-lock.json` Linux binaries (`lightningcss-linux-x64-gnu`, npm/cli#4828) work on Ubuntu.

### Phase 1 — Foundation
- [x] **P1.1** Auth: registration, login, email verification, password reset
- [x] **P1.2** Clubs: create / edit / slug; `club_user` with owner & staff roles; policies
- [x] **P1.3** Club switcher and club-scoped routing (every query scoped to the current club)
- [x] **P1.4** Staff invites (owner invites by email, assigns role)
- [x] **P1.5** Players CRUD: name, DUPR ID (format validation), DUPR rating, stars, active flag
- [x] **P1.6** Star bands: per-club config, rating → stars service, manual override for unrated
- [x] **P1.7** Roster CSV import (name, DUPR ID, rating)
- [x] **P1.8** Rate limiting and abuse guards on signup
- [x] **P1.9** CI job that runs migrations and the test suite against a MySQL 8 service. SQLite ignores `lockForUpdate()` and has different JSON and engine behaviour. Must land before P2.5/P2.7, which rely on row locks. *Split during review:*
  - [x] **P1.9a** `tests-mysql` job (MySQL 8.4, migrate/rollback/migrate, `composer test:mysql`, `REQUIRE_MYSQL` guard) written, reviewed, and passing against local MySQL
  - [x] **P1.9b** *(verified: CI run 37354407656 on `main` @ `5c7a869` succeeded, including Tests (Pest on MySQL 8.4), 2026-10-06)* First green `tests-mysql` run on GitHub Actions for the pushed branch

### Phase 2 — Session engine
- [x] **P2.1** Play sessions: create, courts, scoring config (default: 1 game to 11, side-out), start / end *Split at Phase 2 start:*
  - [x] **P2.1a** Backend: migrations, models, policy, `PlaySessionService`, club session settings
  - [x] **P2.1b** UI: sessions list, create/edit, start/end/delete, club settings fields
- [x] **P2.2** Manual check-in, check-out, break, late arrival *Split at Phase 2 start:*
  - [x] **P2.2a** Backend: `CheckInService` with late-arrival credit
  - [x] **P2.2b** UI: check-in panel on the session page
- [x] **P2.3** Balanced rotation engine (pure PHP service) with config weights
- [x] **P2.4** Rotation engine unit tests: fairness, repeat-partner avoidance, star balance, edge cases (<4 players, odd counts)
- [x] **P2.5** Staging "Up Next", start match on free court, auto-fill option
- [x] **P2.6** Score entry (validates side-out to-11 rules), finish match, undo last result
- [x] **P2.7** Swap / remove a player from a staged or live match; void a match
- [x] **P2.8** Organizer board (Livewire): courts, Up Next, waiting list with wait estimates

### Phase 3 — Live views
- [x] **P3.1** Broadcast events (court/queue/match changes) over Reverb; public channels per session *Split at Phase 3 start:*
  - [x] **P3.1a** Backend: `public_id`, broadcast `session.updated` on `play-session.{public_id}` (queued, deduped per request)
  - [x] **P3.1b** UI: organizer board refreshes live from the channel
- [x] **P3.2** TV / kiosk display (full-screen, auto-updating, readable from distance)
- [x] **P3.3** Public queue page: position, estimated wait, current courts
- [x] **P3.4** "You're up next" browser notification
- [x] **P3.5** Per-session QR code + `checkin_token` (rotates per session, expires when session ends) *Split at Phase 3 start:*
  - [x] **P3.5a** Backend: token issue / regenerate / clear on end, QR SVG service
  - [x] **P3.5b** UI: QR on the organizer board (with regenerate) and on the TV display
- [x] **P3.6** Self check-in: search and pick name; self-register new player (name + nickname + optional DUPR ID + self-rated stars) *Split at Phase 3 start:*
  - [x] **P3.6a** Backend: `players.nickname` / `self_registered_at`, public display name, `SelfCheckInService`, public queue read model (positions + waits)
  - [x] **P3.6b** UI: `/checkin/{token}` search / pick / self-register; nickname on the player form; "new" badge on the board
- [x] **P3.7** Rate limiting on check-in endpoints; organizer can remove bogus check-ins *Split at Phase 3 start:*
  - [x] **P3.7a** Backend: rate limiters, per-session self-register cap, `removeCheckIn` service
  - [x] **P3.7b** UI: "Remove" action on the board
- [ ] **P3.8** Manual browser check. It needs `npm run build`, Reverb and a queue worker running. Check:
  - the TV at 1080p from about 10 m, with 1, 6, 12 and 50 courts
  - full screen and wake lock in the TV browser
  - on a phone, the queue page: the "This is me" picker, and the up-next banner, vibration and notification, both with Reverb running and with it stopped (30-second poll fallback)
  - the self check-in flow, including the star cards
  - what happens on an open TV after "Regenerate QR" and "Reset TV link"

### Phase 4 — DUPR CSV export
- [x] **P4.1** Obtain official DUPR CSV template from club page; commit to `docs/dupr/` *(user supplied `doubles-match-import.csv` (used) and `single-match-import.csv` (reference))*
- [x] **P4.2** Eligibility rules: all 4 players have DUPR IDs, match completed, not voided, not yet exported
- [x] **P4.3** `DuprPublisher` interface + `CsvPublisher` producing the exact DUPR header and row format
- [x] **P4.4** Export page: eligible count, skipped matches, missing-ID player list, download
- [x] **P4.5** Stamp `dupr_exported_at`; export history (`dupr_exports`); re-download past exports
- [x] **P4.6** Tests: golden-file CSV comparison against the official template
- [ ] **P4.7** Manual check: export an ended session and upload the CSV on the DUPR club page (Matches → Add Matches → Import via CSV). Confirm DUPR accepts the header order and rows. *(Added at Phase 4 review: the two samples order columns differently, so only a real upload proves it.)*

### Phase 5 — Stats
- [x] **P5.1** Per-session results and rankings (wins, win %, games played) *Split at Phase 5 start:*
  - [x] **P5.1a** Backend: pure standings ranker in `app/Domain/Stats`, `StatsService` session standings
  - [x] **P5.1b** UI: staff results page; standings on the public ended-session page (when `public_stats` is on)
- [x] **P5.2** All-time club leaderboard *Split at Phase 5 start:*
  - [x] **P5.2a** Backend: `public_stats` / `leaderboard_min_games` club settings, periods, leaderboard query, cached public read
  - [x] **P5.2b** UI: staff and public leaderboard pages with the period dropdown, settings fields, nav link
- [x] **P5.3** Player profile: history, partners, record *Split at Phase 5 start:*
  - [x] **P5.3a** Backend: profile record, partners, opponents, paginated history
  - [x] **P5.3b** UI: profile page, links from the roster and leaderboard
- [x] **P5.4** Session history list and match log *Split at Phase 5 start:*
  - [x] **P5.4a** Backend: match log query, paginated and filtered sessions list with counts
  - [x] **P5.4b** UI: sessions list pagination and filter, match log on the staff and public results pages

### Phase 6 — Deployment
- [ ] **P6.1** Choose hosting (Oracle Cloud free tier vs ~$5/mo VPS)
- [ ] **P6.2** Provision server: PHP, MySQL, Nginx, HTTPS (required for browser notifications)
- [ ] **P6.3** Supervisor for Reverb + queue worker; scheduler cron
- [ ] **P6.4** Deploy script / zero-downtime deploys; backups

### Phase 7 — Later
- [ ] **P7.1** Apply for DUPR Partner API access (see §4)
- [ ] **P7.2** `PartnerApiPublisher` + queued sync jobs + "Sync to DUPR" button (feature-flagged)
- [ ] **P7.3** Auto-fetch DUPR rating by DUPR ID → stars. *Blocked on P7.1 (2026-10-07): the public DUPR API has no rating lookup (see Phase 7 rules).*
- [ ] **P7.4** More rotation modes: Mixed doubles, King/Queen of the Court, Skill courts, Winners/Losers. *Split at P7.4 start. Design: `docs/design/p7.4-rotation-modes.md`. "Winners/Losers" means winners stay.*
  - [x] **P7.4a** Mode framework + gender. Backend: `rotation_mode`/`mode_settings`/`players.gender`, rotation strategy interface (balanced only), mode switch while live, gender on the player form, import, self-register and self check-in. UI: mode select, gender fields. Done when the existing balanced tests pass unchanged.
  - [x] **P7.4b** Mixed doubles: `MixedRotationEngine`, mixed strategy, same-gender swap/remove, the board's "no gender" flag. Done when every team is 1M+1W and the slot waits otherwise.
  - [x] **P7.4c** Skill courts: `SkillGroups`, per-group Up Next and courts, group editor, grouped board/TV/public. Done when a match only ever starts on a court in its group.
  - [ ] **P7.4d** Court-bound staging + winners stay: pending pools, `MatchCompleter`, streaks, void/undo/break rules, max-wins setting. Done when winners stay until max wins and pools stay consistent after void, undo and break.
  - [ ] **P7.4e** King/Queen of the Court: `LadderPlanner`, routing, ladder shrink, first fill by stars, crown/pools UI. Done when an 8-court simulation runs with no deadlock through breaks and court changes.
  - [ ] **P7.4f** Cross-mode adversarial tests on MySQL, plus a manual board check.

### Phase 8 — UI/UX refresh *(branch `feature/p8-ui-refresh`; see Phase 8 rules)*
- [x] **P8.1** Design foundation: brand tokens in `app.css`, light-by-default theme with a dark toggle, refreshed app shell (sidebar, header, mobile nav), auth and public layouts, shared UI components, `docs/design/ui-style.md`
- [x] **P8.2** Landing page at `/`: hero, features, how it works, rotation modes, FAQ, call to action. Guests get sign up / log in, signed-in users get "Go to dashboard".
- [x] **P8.3** Staff pages polish: dashboard, clubs (create, show, settings, members), players and profile, sessions (list, form, board, results, DUPR export), stats, account settings
- [x] **P8.4** Public and guest pages polish: public queue, self check-in, public leaderboard, check-in closed, invitations, auth pages, TV display (stays dark)
- [x] **P8.6** Club overview orientation: show the club's live session (with a "Open board" link) or the last session, plus session and player counts. *(Added after P8.3: the overview page had no data for a live session.)*
- [ ] **P8.5** Manual browser check: light and dark on desktop and phone, the theme toggle persisting across pages, the landing page, and the board in use

### Phase 9 — One name field *(branch `feature/p9-single-name-field`; see "One name field" in the Phase 3 rules)*
- [x] **P9.1** Backend: migration (dedupe names per club, unique `(club_id, name)`, drop `players.nickname` and its index), `Player` public display name = `name`, validation and services (player form, roster import, self-register, self check-in, public read model, stats), demo seeder. Done when no PHP code references `nickname` and a duplicate name in a club is rejected on every write path.
- [x] **P9.2** UI: one required "Name" field on the staff player form and self-register, with a hint that it's shown on the TV and public queue. Remove nickname from the roster, profile, stats tables, check-in panel and self check-in. Done when no Blade view references `nickname`.
- [ ] **P9.3** Manual check on MySQL: run `php artisan migrate` against the local MySQL database with existing players (including same-name players that differ only in case or accents). Confirm the duplicates are renamed with " 2" and the unique index is added. Then add a player in the browser and self-register at `/checkin/{token}` with a name that differs only in case, and confirm both are rejected. *(Added at the P9 review: the MySQL collation path is not covered by the SQLite tests.)*
- [x] **P9.4** Star picker on the staff player form: replace the "Stars" dropdown with clickable stars (1–6) that you tap to set. Read-only when the stars come from the DUPR rating. *(Added 2026-10-08, user request: stars must be actual stars, not a dropdown.)*

### Phase 10 — Live wins and match board *(branch `feature/p10-live-wins-match-board`; added 2026-10-08, user request)*
Rules: "wins" means this session's wins only, counted as in the Phase 5 rules (`done` matches with both scores set; higher score wins). They are shown publicly whatever `public_stats` says (see "Session standings" in the Phase 5 rules). Public pages still expose only `name` and `players.public_id`, plus each player's session games and wins (P10.4).
- [x] **P10.1** Backend: session wins per player in the board read model (`SessionBoard`: checked-in, waiting and break rows) and the public snapshot (`PublicSessionView`: court teams, up-next teams, waiting, on break, players). One grouped query per read, not one per player. Done when a player with 2 won and 1 lost done matches (plus a void and a playing match) shows 2 wins everywhere.
- [x] **P10.2** UI: wins on the board's checked-in list and waiting and break rows. The public queue shows each court as a side-by-side match board: a header with the court number and time, Team A and Team B panels either side of a "VS" badge, one player per line with their wins. Up next uses the same style, smaller. Waiting rows show wins. Done when it reads well on a phone and in light and dark.
- [ ] **P10.3** Manual browser check of the public queue match board on a phone (320 px and 390 px wide) in light and dark: long names, the VS badge never covering text, open courts, the "Players" list with games and wins, the public queue "Share" QR and "Copy link" (also on plain HTTP), the board's three share tabs and "Full screen" QR on a tablet, the board's "N wins" on the check-in and waiting lists, and the board's Match history (P10.7) on a phone and in dark mode, including the row actions menu while the 10-second poll runs. *(Added at the P10 review: the agents could not view the page.)*
- [x] **P10.4** Public queue: remove the wins badges from the match boards, up next, waiting and on break (user feedback 2026-10-08: the trophies were clutter). Add a "Players" section at the bottom listing every checked-in player (not `left`), sorted by name, each row showing "N games · N wins" like the organizer board. Backend: add `games_played` (`session_players.games_played`, the same number the board shows) to the public `players` entries. *(Supersedes the "with their wins" part of P10.2 for the public page; the board is unchanged.)*
- [x] **P10.5** Board share panel: replace the single "Check-in QR and links" panel with three tabs, **Check-in**, **Public queue** and **TV display**. Each tab has its own QR, a one-line "who it's for", a copy-link field, an "Open" button, a "Full screen" QR view for players to scan (Check-in and Public queue only, never TV, because the TV link is private), and only that tab's own action ("Regenerate QR" on Check-in, "Reset TV link" on TV). After the session ends, Check-in says check-in is closed and the queue tab still works. Backend: a generic `CheckInQrService::svgForUrl(string $url, int $size = 256)` that `svg()` uses. *(Added 2026-10-08, user request. The TV QR is staff-only because the TV link is private.)*
- [x] **P10.6** Public queue page: a "Share" button in the header opens the page's own QR (its `/c/{club}/s/{public_id}` URL) so other players can scan it from someone's phone, plus a copy-link button and the native share sheet where it's supported. Never show the check-in or TV QR there. *(Added 2026-10-08, user request.)*
- [x] **P10.7** Board "Recent results" becomes **Match history**: a clean, compact list of the session's `done` matches, newest first, each row showing the match number (finish order), court, finish time, both teams ("A & B vs C & D"), the score, and **who won** (winning team highlighted with a "Won" marker; no winner shown when the scores are missing or tied, as in the Phase 5 rules). It shows 20 at first, with "Show more" adding 20. Edit score, Undo last result and Void stay, but as a small per-row actions menu instead of big buttons. The inline edit and void panels and their rules are unchanged. Backend: `SessionBoard::recent()` rows gain `number`, `winner` (`'A'`, `'B'` or `null`) and `duration_minutes`, plus `SessionBoard::doneCount()`. *(Added 2026-10-08, user request: the old panel showed only the last 5 as large cards.)* The match number is the position among the session's current done matches, so voiding an earlier match or undoing the last one renumbers the later rows. That's accepted, because the number is for display only. *(From the P10.7 review.)*

### Phase 11 — Shareable results *(branch `feature/p11-shareable-results`; added 2026-10-09, user request; see Phase 11 rules)*
- [x] **P11.1** Remove `public_stats`: a migration drops the column, and the model, factory, settings page, `ClubService` and `StatsService` gates go. The public leaderboard and ended-session results are always on. Update the tests that relied on the switch.
- [x] **P11.2** Backend read models: results for one session (hero totals, podium, highlights, prev/next ended sessions; public version cached, done matches only, public ids only), and the public past sessions list (live banner, ended sessions with counts and #1 player, paginated, no per-session queries, cached).
- [x] **P11.3** Podium images: GD renderer, GIF89a encoder, bundled font, `podium.gif` / `podium.png` routes with caching, ETag and throttle. Unit tests for the encoder (valid GIF89a, loop extension, frame count) and feature tests for the routes (404 for live, draft, other club and no matches).
- [x] **P11.4** UI: the redesigned results page (hero, animated podium, highlights, standings, match log, share panel) on the staff page and the public ended page, the public club header and tabs, prev/next links, the public sessions page and `/c/{club}` redirect, Open Graph and Twitter tags, and `noindex` on public stats pages.
- [ ] **P11.5** Manual check: open a real ended session's share link on a phone in light and dark mode. Paste it into Facebook / Messenger / WhatsApp and check the preview image. Download the GIF and post it. Check long names in the GIF and on the podium, and check prev/next and the sessions list.
- [x] **P11.6** Screenshot-ready tables (see Phase 11 rules): restyle `x-stats.table` (standings, staff and public leaderboards, with "N / min games" progress on the unranked list) and `x-stats.match-log` (winner highlight, score pill, court badge, "1 h 5 min" durations, a date shown when a match finished on another day). Phone layout without sideways scrolling, light and dark, and existing `data-test` hooks kept. *(Added 2026-10-09, user request.)*

---

## 6. Risks and open items

- **DUPR may decline API access** for a personal app → CSV covers the full workflow.
- **Name-only check-in can be abused** → per-session QR token that expires, rate limits, organizer can remove anyone.
- **Public signup attracts spam clubs** → email verification + rate limits.
- **Per-club players** → the same person in two clubs is two records; stats don't combine across clubs. Changing this later needs a data migration.
- **DUPR template exactness** → never guess the header row; always build from the committed official template.
- **Reverb hardening (P6.3):** `config/reverb.php` has `allowed_origins => ['*']` and binds `0.0.0.0`. In production, restrict origins to `APP_URL` and bind to 127.0.0.1 behind Nginx. `VITE_REVERB_*` values are baked in at build time, so the production build needs the real `.env`. Echo currently connects on every page; consider loading it only on live pages.
- **Proxy IPs (P6.2):** the signup throttle is per IP. Behind Nginx or a load balancer, configure TrustProxies. Otherwise every visitor shares one IP and the whole site gets only 5 signups an hour.
- **Larastan doesn't analyse `⚡*.blade.php` single-file components.** Their PHP runs without level 8 checks. *Resolved for Phase 2:* components with logic are class-based in `app/Livewire` (see Phase 2 rules). The existing Phase 1 SFCs are still unanalysed.
- **Livewire `memo.path` after a slug rename:** other open tabs get a 404 on their next action. Data stays safe. Keep this in mind for the Phase 2 board, which stays open for hours.
- **Windows-generated `package-lock.json`** can drop nested Linux native binaries (npm/cli#4828). If `npm ci` or `npm run build` fails on Linux with a missing lightningcss binding, regenerate the lock on Linux.
- **Name case and accent folding (from the P3 review, low; moved from nickname to name in P9):** MySQL's collation makes the `(club_id, name)` index case- and accent-insensitive. The SQLite tests fold only ASCII. Every write path catches the unique violation, so production is safe. If this ever matters, add a normalized `name_key` column.
- **Nicknames in the DUPR CSV (P9, low):** the CSV's player name columns carry `name` as entered, which may now be a nickname like "Ace". DUPR identifies players by the DUPR ID column. P4.7 (the real upload) should include a player with a nickname-style name, to confirm DUPR accepts it.
- **Parallel test runs collide:** two `composer test` runs at the same time (e.g. two subagents) share the `livewire-tmp` upload folder, and the roster-import tests fail intermittently. Run the full suite from one place at a time. A failure seen only under parallel runs isn't a real regression.
- **Stats period filter and indexes (from the P5 review, low):** the period filter uses `whereDate()` on `play_sessions.date`, so MySQL can't use an index on that column. Queries are already limited by `club_id`, so this is fine at club scale. If the leaderboard gets slow, switch to `whereBetween` on normalised bounds and add a `(club_id, date)` index.
- **Missing wait estimates in mixed mode (from the P7.4b review, low):** in mixed mode, the TV and public queue show "–" for any waiting player who can't get an estimate: players with no gender, and players whose gender doesn't have enough partners from the other gender waiting. Someone watching could guess that a "–" player might have no gender on record. We accept this, because the gender value itself is never shown publicly.
- **Formula injection in the DUPR CSV (from the P4 design, low):** player names are exported as entered, because DUPR needs the exact names. A self-registered name that starts with `=`, `+`, `-` or `@` could run as a formula if staff open the file in Excel. We accept this risk: the file is meant to be uploaded to DUPR rather than opened, and staff can see self-registered players on the board ("new" badge).
