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
clubs:            name, slug, dupr_club_id, star_bands(json), default_courts
club_invitations: club_id, email, role, token_hash, invited_by, expires_at, accepted_at
players:          club_id, name, dupr_id?, dupr_rating?, stars, rating_source(manual|dupr), active
play_sessions:    club_id, name, date, courts, scoring(json), status(draft|live|ended), checkin_token
session_players:  play_session_id, player_id, status(waiting|playing|break|left), checked_in_at,
                  games_played, last_finished_at
matches:          play_session_id, court_no, status(staged|playing|done|void), team_a_score, team_b_score,
                  started_at, finished_at, dupr_eligible, dupr_exported_at, dupr_synced_at, dupr_match_ref
match_players:    match_id, player_id, team(A|B), slot(1|2)
dupr_exports:     play_session_id, user_id, match_count, file_path
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

---

## 3. Balanced rotation engine

1. **Priority order** of the waiting list: fewest games played → longest wait since last game.
2. **Candidates:** take the top 6–8 waiting players; evaluate every 4-player group × its 3 possible team splits.
3. **Cost (lowest wins):**
   `|stars(A) − stars(B)| × w1 + repeat partners × w2 + repeat opponents × w3 + skipped-priority × w4`
4. **Stage** the winner as "Up Next"; when a court frees, the organizer taps to start (or auto-fill).

Pure PHP service — no DB or UI dependency — so it is fully unit-testable. Weights live in config.

---

## 4. DUPR integration

- **CSV export (v1):** match DUPR's official club template header row **verbatim**. The template must be downloaded from the DUPR club page (Matches → Add Matches → Import via CSV → Download Template) and committed to `docs/dupr/` before building the exporter. One row per game, slots A1/A2/B1/B2 with names and DUPR IDs.
- **Export page:** shows eligible count, skipped matches and which players are missing a DUPR ID. Exporting stamps `dupr_exported_at` so nothing is uploaded twice.
- **Abstraction:** `DuprPublisher` interface → `CsvPublisher` (now) and `PartnerApiPublisher` (later). Enabling sync is a config/flag change, not a rewrite.
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
| TV display | `/c/{club}/s/{session}/tv` | Public, read-only |
| Public queue | `/c/{club}/s/{session}` | Players |
| QR check-in | `/checkin/{token}` | Players |
| Roster | `/clubs/{club}/players` | Staff |
| Stats & leaderboard | `/clubs/{club}/stats` | Staff / public |
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
- [ ] **P1.9** CI job that runs migrations and the test suite against a MySQL 8 service. SQLite ignores `lockForUpdate()` and has different JSON and engine behaviour. Must land before P2.5/P2.7, which rely on row locks. *Split during review:*
  - [x] **P1.9a** `tests-mysql` job (MySQL 8.4, migrate/rollback/migrate, `composer test:mysql`, `REQUIRE_MYSQL` guard) written, reviewed, and passing against local MySQL
  - [ ] **P1.9b** First green `tests-mysql` run on GitHub Actions for the pushed branch

### Phase 2 — Session engine
- [ ] **P2.1** Play sessions: create, courts, scoring config (default: 1 game to 11, side-out), start / end
- [ ] **P2.2** Manual check-in, check-out, break, late arrival
- [ ] **P2.3** Balanced rotation engine (pure PHP service) with config weights
- [ ] **P2.4** Rotation engine unit tests: fairness, repeat-partner avoidance, star balance, edge cases (<4 players, odd counts)
- [ ] **P2.5** Staging "Up Next", start match on free court, auto-fill option
- [ ] **P2.6** Score entry (validates side-out to-11 rules), finish match, undo last result
- [ ] **P2.7** Swap / remove a player from a staged or live match; void a match
- [ ] **P2.8** Organizer board (Livewire): courts, Up Next, waiting list with wait estimates

### Phase 3 — Live views
- [ ] **P3.1** Broadcast events (court/queue/match changes) over Reverb; public channels per session
- [ ] **P3.2** TV / kiosk display (full-screen, auto-updating, readable from distance)
- [ ] **P3.3** Public queue page: position, estimated wait, current courts
- [ ] **P3.4** "You're up next" browser notification
- [ ] **P3.5** Per-session QR code + `checkin_token` (rotates per session, expires when session ends)
- [ ] **P3.6** Self check-in: search and pick name; self-register new player (name + optional DUPR ID)
- [ ] **P3.7** Rate limiting on check-in endpoints; organizer can remove bogus check-ins

### Phase 4 — DUPR CSV export
- [ ] **P4.1** Obtain official DUPR CSV template from club page; commit to `docs/dupr/`
- [ ] **P4.2** Eligibility rules: all 4 players have DUPR IDs, match completed, not voided, not yet exported
- [ ] **P4.3** `DuprPublisher` interface + `CsvPublisher` producing the exact DUPR header and row format
- [ ] **P4.4** Export page: eligible count, skipped matches, missing-ID player list, download
- [ ] **P4.5** Stamp `dupr_exported_at`; export history (`dupr_exports`); re-download past exports
- [ ] **P4.6** Tests: golden-file CSV comparison against the official template

### Phase 5 — Stats
- [ ] **P5.1** Per-session results and rankings (wins, win %, games played)
- [ ] **P5.2** All-time club leaderboard
- [ ] **P5.3** Player profile: history, partners, record
- [ ] **P5.4** Session history list and match log

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
- **Larastan doesn't analyse `⚡*.blade.php` single-file components.** Their PHP runs without level 8 checks. Look at adding those paths, or extracting logic, before Phase 2 adds heavier board components.
- **Livewire `memo.path` after a slug rename:** other open tabs get a 404 on their next action. Data stays safe. Keep this in mind for the Phase 2 board, which stays open for hours.
- **Windows-generated `package-lock.json`** can drop nested Linux native binaries (npm/cli#4828). If `npm ci` or `npm run build` fails on Linux with a missing lightningcss binding, regenerate the lock on Linux.
