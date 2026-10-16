# Pre-launch plan (alpha / beta)

What to do before PickleQ+ goes on a public host for alpha and beta testers. Written 2026-10-16 against `main` @ `a35490d` (after P16.9).

This is a working note, not the checklist. When you decide to deploy, the items here get added to `PLAN.md` (mostly as new Phase 6 items) and are ticked there.

---

## 1. Where things stand

- **Built:** Phases 0–5 and 8–16. Everything left unticked in those phases is a manual browser check (P3.8, P8.5, P10.3, P11.5, P12.3, P14.12, P15.2, P16.7), plus P9.3 (migration on MySQL) and P4.7 (a real DUPR CSV upload).
- **Phase 7 leftovers can wait.** P7.4d (Winners stay), P7.4e (King of the Court) and P7.4f (cross-mode tests) aren't needed for an alpha. Those modes are already turned off: `config/pickleq.php` → `rotation_modes_enabled` lists only `balanced`, `mixed`, `skill_courts` and `social`. The landing page marks the other two as not ready.
- **Phase 6 (deployment) hasn't started:** P6.1–P6.4.
- **Suite status on 2026-10-16:**
  - `composer test`: 1,450 tests, 1,444 passed, 6 skipped (the MySQL-only tests), 0 failed. A full run takes about 8 minutes.
  - `composer lint`: Pint and Larastan both clean.

---

## 2. Fix before alpha users arrive (must do)

- [ ] **Trusted proxies.** `bootstrap/app.php` doesn't configure TrustProxies. Behind Nginx or Cloudflare, every visitor would look like the same IP address. The signup limit, the guest-start limit and the check-in limits would then apply to everyone together, so a handful of users could lock out the whole site. (§6 Risks: "Proxy IPs".)
- [ ] **Reverb hardening.** `config/reverb.php` currently accepts connections from any website (`allowed_origins => ['*']`) and listens on every network interface (`0.0.0.0`).
  - In production, allow only the `APP_URL` host. Read both values from env.
  - Have Reverb listen on `127.0.0.1` only, with Nginx forwarding WebSocket traffic to it.
  - The `VITE_REVERB_*` values are fixed when the front end is built, so build with the production `.env`. (§6 Risks: "Reverb hardening".)
- [ ] **A real mail provider.** `.env.example` has `MAIL_MAILER=log`, which means password resets and staff invites (P1.4) are never sent. Pick a provider with a free tier (Resend, Brevo, Postmark or Amazon SES) and set up SPF and DKIM for the sending domain.
- [ ] **Email verification back on** (P13.2): set `PICKLEQ_EMAIL_VERIFICATION=true`, then run `php artisan optimize`. Do this once mail works. It's the main protection against spam signups.
- [ ] **Production environment settings:**
  - `APP_ENV=production` and `APP_DEBUG=false`
  - `LOG_LEVEL=warning` and `LOG_STACK=daily`
  - `SESSION_SECURE_COOKIE=true`
  - A real `APP_URL` on HTTPS

  HTTPS is required for browser notifications (P3.4), passkeys (P13.3) and the guest cookie (§6 Risks: "Guest organizer link is a bearer credential").
- [ ] **Long-running processes (P6.3):**
  - Supervisor for `php artisan reverb:start` and `php artisan queue:work`.
  - The scheduler cron: `* * * * * php artisan schedule:run`. Without it, guest clubs are never purged, which breaks the 24 h / 72 h promise.
- [ ] **Backups (P6.4).** A nightly MySQL dump copied off the server (e.g. to S3, Backblaze B2 or Google Drive), plus `storage/app` (podium images). Test a restore once.
- [ ] **Deploy routine (P6.4).** The deploy script does these in order:
  1. `php artisan down` (maintenance mode)
  2. `composer install --no-dev`
  3. `npm ci && npm run build`
  4. `php artisan migrate --force`
  5. `php artisan optimize`
  6. `php artisan view:clear`, because stale compiled views have bitten before (§6 Risks)
  7. Restart Reverb and the queue worker
  8. `php artisan up`

---

## 3. Strongly suggested for a beta (new items)

None of these are in `PLAN.md` yet. Decide which to take, then add them as new items.

- [ ] **"Send feedback" link** in the app shell and the public footer. It can be a `mailto:`, a Google Form or a small feedback form. Collecting feedback is the main reason to run an alpha.
- [ ] **Error monitoring:** Sentry, Flare or Laravel Nightwatch, or at least daily logs plus an email when errors happen. Otherwise you won't see crashes that testers don't report. This adds a package, so record the reason with the change.
- [ ] **Branded error pages** in `resources/views/errors/`: 404, 419, 429, 500 and 503. The board stays open for hours, and the default pages look broken. The 503 page also covers the deploy window. There are none today.
- [ ] **Privacy page,** plus short terms. The app stores names, emails, DUPR IDs and gender. Once people outside your own group use it, the Philippine Data Privacy Act (RA 10173) applies.
- [ ] **Fix the flaky "2W" test** in `tests/Feature/Sessions/SessionWinsTest.php`. It should check the badge element instead of the raw HTML (§6 Risks: "Flaky '2W' assertion"). Otherwise it can randomly fail CI and block a deploy.
- [ ] **Health check:** point an uptime monitor (UptimeRobot, Better Stack, etc.) at the built-in `/up` route. Also consider an alert when the scheduler's last guest purge is too old (§6 Risks: "Guest purge depends on the scheduler").

---

## 4. Manual checks: before or after deploying?

**Locally, before deploying** (`npm run build`, plus Reverb, the queue worker and the scheduler running):
- [ ] The core open-play flow, which covers P16.7 and the main part of P3.8:
  1. Build the player list (paste and Reclub import)
  2. Check players in (board, check in all, public check-in with and without a password)
  3. Run the board and enter scores
  4. Watch the TV display and the public queue update live
- [ ] P9.3: the name-dedupe migration on the local MySQL database with real-looking data.

**On the deployed site** (these need a public HTTPS address or real devices):
- [ ] P3.8 (rest): TV from a distance, browser notifications, the poll fallback with Reverb stopped
- [ ] P8.5: light and dark mode, the theme toggle, landing page
- [ ] P10.3: public queue match board on phones
- [ ] P11.5: share-link previews in Facebook, Messenger and WhatsApp, and the podium GIF
- [ ] P12.3: session form check-in and quick-add on a phone
- [ ] P14.12: the guest session end to end, including expiry, purge and claim
- [ ] P15.2: board full screen on tablets and phones
- [ ] P4.7: export a real ended session and upload the CSV to DUPR
- [ ] P13.3: switch passkeys back on once HTTPS is stable (`PICKLEQ_PASSKEYS=true`)

---

## 5. Suggested order

1. **Hardening batch:** trusted proxies, Reverb config from env, and the beta extras you choose from section 3. Add them to `PLAN.md` as new Phase 6 items first.
2. **Local smoke test** (section 4, "before deploying").
3. **Decide hosting** (P6.1).
4. **Server setup** (P6.2–P6.4): provision the server, then set up HTTPS, Supervisor, the cron, backups and the deploy script.
5. **Turn on mail and verification** (P13.2), then passkeys (P13.3).
6. **Smoke test on the server,** then invite the first testers. Work through the "on the deployed site" checks during the alpha.

---

## 6. Decisions needed from you

- [ ] **Hosting (P6.1):** Oracle Cloud free tier, or a ~$5/month VPS (e.g. Hetzner, DigitalOcean)?
- [ ] **Domain:** do you have one, or does one need buying? HTTPS needs a real domain.
- [ ] **Mail provider:** Resend, Brevo, Postmark, Amazon SES, or another?
- [ ] **Beta extras:** which of the section 3 items to include?
- [ ] **Signups during the alpha:** open to anyone (with verification and rate limits), or invite-only?
