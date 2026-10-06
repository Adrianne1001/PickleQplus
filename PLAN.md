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
| Rotation | **Balanced** only in v1. Engine designed so more modes can plug in later. |
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
players:          club_id, public_id, name, nickname?, dupr_id?, dupr_rating?, stars, rating_source(manual|dupr), active,
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
- **Roster import:** UTF-8 CSV with a header row `name,dupr_id,dupr_rating`. The header is matched case-insensitively, and only `name` is required. Limits are 1 MB and 1,000 rows. A row matches an existing player by DUPR ID first, then by case-insensitive name. Matches are updated and new rows are created. **A name match never overwrites a different existing DUPR ID**, because it could be a different person with the same name. That row becomes an error. A blank cell never clears an existing value. Staff see a preview (create / update / skip / error per row) before confirming.
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
- **Nicknames (user decision):** public and TV pages show a player's **nickname**, not their full name. `players.nickname` is nullable, max 20 characters, and unique per club (case-insensitive) when set. If it isn't set, the public display name falls back to first name + last initial ("Adrianne B."). Staff pages show the full name, with the nickname alongside. Staff can edit the nickname.
- **Public queue page (P3.3):** shows current courts, Up Next and the waiting list, with positions and estimated waits from `WaitEstimator`. A visitor can tap **"This is me"** to pick themselves from the checked-in list. That choice is stored only in the browser (localStorage per session) and is set automatically after a self check-in on that device. The server never shows who is watching.
- **"You're up next" (P3.4, user decision):** page-open only, so there's no Web Push or service worker. When the "me" player enters a staged match, or a staged match with them starts on a court, the page shows a banner, vibrates (where supported) and fires a browser `Notification` if permission was granted. Permission is requested only from a user tap. This works while the page or tab is open, including in the background. It needs HTTPS in production (P6.2).
- **Self check-in (P3.6):** `/checkin/{token}` is valid only while the token's session is draft or live. Otherwise it shows a friendly "check-in closed" page. Players **search** by name or nickname, with a minimum of 2 characters and at most 10 active players returned. Search results show the full name and nickname, because this page is reachable only via the venue QR. Picking a name checks the player in through `CheckInService`, so the usual late-arrival credit and single-live-session rules apply. If the player is already checked in, the page says so. Search results report each player's session status (not checked in / waiting / playing / on break). Picking a player who is on break returns them from break, which is the same as at the desk. Every self check-in call carries the **token** and re-checks it inside the session lock, so a page left open with a regenerated (old) QR stops working at once. If the player has no nickname, they may set one at check-in, but they can never overwrite an existing one.
- **Self-register (P3.6, user decision):** the form takes name, **nickname (required)**, an optional DUPR ID, and **self-rated stars 1–6**. Each level has a short plain-language description. The player is created with `rating_source = manual`, with `self_registered_at` and `self_registered_session_id` set, then checked in. "Self-registered in this session" means `self_registered_session_id` equals that session. A DUPR ID or nickname already in the club is rejected with "you're already on the roster, search for your name". The board shows a **"new"** badge on players self-registered in the current session, so staff can check their level.
- **Abuse guards (P3.7):** the limits are per IP and per session:
  - search: 60 per minute
  - check-in or self-register submits: 10 per minute
  - self-registrations: 5 per hour per IP, and at most 100 per session. Only **successful** registrations count toward the 5. Rejected attempts are capped by the submit limit. This is because a whole venue often shares one IP.

  Staff can **remove** a bogus check-in from the board. Removing deletes the `session_players` row, but only if the player has no staged, playing or done match in the session. Otherwise staff use check-out. If the removed player was self-registered in that session and has no matches anywhere, the player record is deleted too. This is the one exception to "players aren't hard-deleted", because these are spam records with no history.

**Phase 5 rules (decided at Phase 5 start; change here first if needed):**
- **What counts:** stats use only matches with `status = done` and both scores set. Void, staged and playing matches never count. The source of truth is `matches`/`match_players`, not `session_players.games_played`. The winner is the team with the higher score (valid scores have no ties). Per player: `played`, `wins`, `losses`, `win %` (wins ÷ played), `points for`, `points against`, `point diff`. DUPR status doesn't affect stats.
- **Visibility (user decision):** every stats page is available to club members (owners and staff), with the usual Phase 1 club scoping. New club setting **`public_stats`** (owner, checkbox, **default off**). When it's on:
  - a public leaderboard at `/c/{club:slug}/stats`
  - the public page of an **ended** session (`/c/{club:slug}/s/{public_id}`) shows its final standings and match log instead of only "session ended"

  When it's off, `/c/{club:slug}/stats` is a 404 and the ended page is unchanged. Public pages show the public display name only (nickname, or first name + last initial), never full names, DUPR IDs or any id except `players.public_id`. **Player profiles are always staff-only.**
- **Session standings (P5.1):** for one session, rank by wins desc → win % desc → point diff desc → name asc. There's no minimum number of games. Rows: rank, player, played, W, L, win %, point diff. On staff pages, a live session shows "standings so far". Publicly, standings appear only once the session has ended.
- **Leaderboard (P5.2, user decision):** ranked by **win %** desc → wins desc → point diff desc. Only players with at least **`leaderboard_min_games`** games in the period get a rank. That's a new club setting (owner, 1–100, **default 10**). Everyone else is listed below the ranked players as "not ranked yet", sorted by played desc and then name. Players tied on all three keys share a rank (1, 2, 2, 4), and the display order inside a tie is by name. Win % is compared exactly (cross-multiplied, not as floats) and shown as a whole-number percentage. Inactive players are left out of the leaderboard. Their profiles and history stay available to staff.
- **Periods (user decision):** `all_time` (default), `this_month`, `last_30_days` and `this_year`, picked from a dropdown. A period filters on the session's `date` in the app timezone. `last_30_days` means today and the 29 days before it. The same ranking rules and minimum apply to every period. The leaderboard and profile both take the period. The public leaderboard has the same dropdown.
- **Player profile (P5.3):** `/clubs/{club:slug}/players/{player}` (staff only, scoped binding). It shows:
  - the header (name, nickname, DUPR ID and rating, stars, active)
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
  | `playerA1`… `playerB2` | the player's full `name` (never the nickname). A1 = team A slot 1, A2 = team A slot 2, B1/B2 likewise for team B |
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
| Public leaderboard | `/c/{club}/stats` (only if the club's `public_stats` is on) | Public |
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
- [ ] **P7.3** Auto-fetch DUPR rating by DUPR ID → stars
- [ ] **P7.4** More rotation modes: Mixed doubles, King/Queen of the Court, Skill courts, Winners/Losers

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
- **Nickname case and accent folding (from the P3 review, low):** MySQL's collation makes the `(club_id, nickname)` index case- and accent-insensitive. The SQLite tests fold only ASCII. Every write path catches the unique violation, so production is safe. If this ever matters, add a normalized `nickname_key` column.
- **Parallel test runs collide:** two `composer test` runs at the same time (e.g. two subagents) share the `livewire-tmp` upload folder, and the roster-import tests fail intermittently. Run the full suite from one place at a time. A failure seen only under parallel runs isn't a real regression.
- **Stats period filter and indexes (from the P5 review, low):** the period filter uses `whereDate()` on `play_sessions.date`, so MySQL can't use an index on that column. Queries are already limited by `club_id`, so this is fine at club scale. If the leaderboard gets slow, switch to `whereBetween` on normalised bounds and add a `(club_id, date)` index.
- **Formula injection in the DUPR CSV (from the P4 design, low):** player names are exported as entered, because DUPR needs the exact names. A self-registered name that starts with `=`, `+`, `-` or `@` could run as a formula if staff open the file in Excel. We accept this risk: the file is meant to be uploaded to DUPR rather than opened, and staff can see self-registered players on the board ("new" badge).
