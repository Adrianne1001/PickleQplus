# CLAUDE.md

PickleQ+ is a free, self-hosted Laravel app for pickleball open play: queue and court rotation, live TV and phone views, QR self check-in, stats, and DUPR integration. **`PLAN.md` is the source of truth** for decisions, the data model, and the checklist.

## Checklist rule (mandatory)

`PLAN.md` holds the progress checklist. Every item has an ID like `P2.3`.

- **When an item is completed, tick it (`- [ ]` → `- [x]`) in `PLAN.md` in the same change that completes it.** Don't leave it for later, and don't batch it up.
- In the orchestrator workflow, **only the orchestrator edits `PLAN.md`**. Subagents report which item IDs they finished, and the orchestrator ticks each one once it's verified. In a session without the orchestrator, whoever completes the item ticks it.
- An item is complete only when its code is written, its tests pass (`composer test`), and lint is clean (`composer lint`). Partly done work stays unticked.
- If an item turns out to be wrong, too big, or missing, edit the checklist: split it (`P2.3a`, `P2.3b`), add an item, or strike it through with a one-line reason. Never delete history silently.
- If a decision in `PLAN.md` changes, update that section in the same change.
- Reference the item ID in commit messages, e.g. `P2.3: balanced rotation engine`.

## How work is organised

The main session runs as the **orchestrator** agent (`.claude/agents/orchestrator.md`). It plans, breaks checklist items into tasks, delegates them to specialist subagents, checks the results, and keeps `PLAN.md` up to date.

| Agent | Owns |
|---|---|
| `laravel-backend` | Migrations, models, policies, services (including the rotation engine), controllers, jobs, events |
| `livewire-frontend` | Livewire components, Blade views, Tailwind, Echo/Reverb client, TV and public pages |
| `dupr-integration` | DUPR CSV export, `DuprPublisher` implementations, DUPR Partner API (later) |
| `test-engineer` | Pest tests, running the suite, finding the cause of failures |
| `code-reviewer` | Read-only review of changes against `PLAN.md` and these conventions |
| `devops` | CI, environment config, Reverb/queue/Supervisor, deployment |

## New session reminders

Remind the user to **start a new session** whenever a fresh one would work better. Say so at the end of your reply, with one line explaining why. Recommend one when:

- `CLAUDE.md`, `.claude/settings.json`, or any file in `.claude/agents/` has changed. Those changes only take effect in a new session.
- A phase in `PLAN.md` is finished and the next one is about to start.
- The conversation is long or has been summarized, and the next task is unrelated to what came before.
- The work is switching to a very different area (for example, from backend to deployment).

Before recommending a new session, make sure `PLAN.md` is up to date, so the new session can pick up from the checklist.

## Stack and commands

- Latest Laravel, Livewire, Laravel Reverb, Tailwind CSS, MySQL, Pest, Pint, Larastan.
- `composer test` runs the test suite. `composer lint` runs Pint (check mode) and Larastan.
- `php artisan reverb:start` and `php artisan queue:work` are needed for live features locally.
- Dev environment is Windows. Use paths and commands that also work on Linux (the server).

## Conventions

- **Club scoping:** every club-owned query is scoped to the current club, and policies enforce it. Never trust a `club_id` from the request.
- **Fat services, thin components:** domain logic lives in `app/Services` (or `app/Domain/...`). Livewire components and controllers just call it.
- **The rotation engine is pure PHP:** no Eloquent, no facades. Plain data goes in and a result comes out, so it's fully unit-testable.
- **DUPR CSV header row:** copy it verbatim from the official template in `docs/dupr/`. Never guess column names.
- **Broadcasting:** fire domain events when courts, queues or matches change. Public pages listen on per-session channels.
- Every new feature comes with feature tests. Every service gets unit tests.
- Follow Laravel defaults and naming. Don't add packages without a reason in the change.

## Git

- Branch per phase or feature (`feature/p2-rotation-engine`). Don't commit directly to `main`.
- Commit or push only when the user asks.
