# PickleQ+

A free, self-hosted Laravel app for pickleball open play: queue and court rotation, live TV and phone views, QR self check-in, stats, and DUPR integration (CSV export first, API sync later). Inspired by PickleQ.

The plan, decisions, data model and progress checklist live in [`PLAN.md`](PLAN.md). Contributor and agent conventions are in [`CLAUDE.md`](CLAUDE.md).

## Development setup

### Requirements

- PHP 8.3+ with the extensions `mbstring`, `intl`, `pdo_mysql`, `pdo_sqlite`, `sqlite3`, `gd`, `zip`, `bcmath`, `fileinfo`
- Composer 2
- Node.js 22+ and npm
- MySQL 8 (the app database; tests use SQLite in-memory, so MySQL is not needed to run them)

### First-time install

Order matters: create the MySQL database `pickleq` before running `composer setup` or `php artisan migrate`, because both run migrations against it. The manual steps below follow that order. (`composer setup` is a shortcut for install, `.env` copy, `key:generate`, `migrate`, `npm install` and `npm run build`; it does not set the Reverb values or create the database, so do those first.)

These commands work in Git Bash on Windows and in a Linux shell.

```bash
git clone <repo-url> pickleq && cd pickleq

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Edit `.env`:

- Set `DB_USERNAME` / `DB_PASSWORD` for your MySQL server.
- Fill in `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET`. They are empty in `.env.example` on purpose (so a deploy that forgets them fails fast). Laravel has no artisan command that generates them, so create random values yourself, once per secret:

  ```bash
  php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
  ```

  Use any short value for the app ID (for example `pickleq`), and a separate generated value for each of the key and the secret. `VITE_REVERB_APP_KEY` reads `REVERB_APP_KEY` automatically, so run `npm run dev` (or `npm run build`) again after changing the key.

Create the database and migrate:

```bash
# any MySQL client works; example with the mysql CLI
mysql -u root -p -e "CREATE DATABASE pickleq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

php artisan migrate
```

If you have no `mysql` CLI (common on Windows), create the database from your GUI client (HeidiSQL, DBeaver, phpMyAdmin) instead.

### Run it

```bash
composer dev
```

This runs the web server (http://localhost:8000), Vite, the Reverb WebSocket server (port 8080) and the queue worker together. Press Ctrl+C to stop them all.

To run the pieces separately (one terminal each):

```bash
php artisan serve
npm run dev
php artisan reverb:start
php artisan queue:work
```

Live features (TV and phone views) need Reverb and the queue worker running. Emails are written to `storage/logs/laravel.log` (`MAIL_MAILER=log`).

### Tests and code quality

```bash
composer test     # Pest test suite (SQLite in-memory)
composer lint     # Pint (check mode) + Larastan (PHPStan level 8); exits non-zero on any problem
composer format   # fix code style with Pint
```

Always run tests through `composer test`, not bare `php artisan test` or `vendor/bin/pest`. The script clears cached config first, so a stale cached config can never point the tests at the MySQL database.

`laravel/pao` is a dev-only package that compacts test and lint output when an AI agent runs them. Set `PAO_DISABLE=1` (for example `PAO_DISABLE=1 composer test`) to get the normal full output.

Tests need the Vite manifest for views that render assets, so run `npm run build` once before the first `composer test` on a fresh clone.

CI (`.github/workflows/ci.yml`) runs `composer lint` and `composer test` on pushes to `main`, on every pull request, and on manual dispatch (`workflow_dispatch`).
