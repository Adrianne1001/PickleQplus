# PickleQ+ UI style guide

Visual rules for every page. Decided in `PLAN.md` -> "Phase 8 rules". Stack: Tailwind v4 utilities, Flux components, Blade anonymous components. No new packages.

## 1. Tokens (`resources/css/app.css`)

| Token | Use |
|---|---|
| `brand-50..950` (`bg-brand-700`, `text-brand-700`) | Pickleball green. `brand-700` is the main fill. |
| `ball` (`text-ball`, `bg-ball/30`) | Pickleball lime. **Decoration only** (logo, glows). Never as text on white. |
| `accent`, `accent-content`, `accent-foreground` | Flux's colours. Flux `variant="primary"` buttons, links and focus rings already use them. |
| `zinc-*` | Neutral base (the kit maps zinc to pure grays). |
| Font | Plus Jakarta Sans (400 to 800, loaded by the `@fonts` directive from `vite.config.js`). |

Contrast (all pass WCAG AA):
- Light: white on `brand-700` is 5.0:1. Accent text (`accent-content`) is `brand-700`.
- Dark: the accent fill stays `brand-700` (white on it still 5.0:1). `accent-content` becomes `brand-400` for links and text on dark surfaces.
- Body text: `text-zinc-900 dark:text-white`. Secondary text: `text-zinc-600 dark:text-zinc-400`. **Never go lighter than `zinc-600` / `dark:zinc-400` for text that matters.** `zinc-500` is only for tiny hints on white.

## 2. Light and dark

- **Light is the default** for first-time visitors on every layout. The choice is stored under Flux's key `flux.appearance` (`light` | `dark` | `system`), so Settings -> Appearance (`$flux.appearance`), the toggle and every layout share it.
- `resources/views/partials/appearance.blade.php` sets the class before first paint (no flash). It is included by `partials/head.blade.php` (app, auth, welcome) and by `layouts/public.blade.php`. **Do not use `@fluxAppearance` or hard-code `class="dark"` on `<html>`.**
- `layouts.public` with `dark: true` (the TV page) is always dark and shows no toggle.
- Every colour utility needs a `dark:` partner. Page surface: `bg-zinc-50 dark:bg-zinc-950`. Card surface: `bg-white dark:bg-zinc-900`. Border: `border-zinc-200 dark:border-zinc-700` (the base layer already sets dark borders to `zinc-700`).
- Status colours: tinted fill plus strong text in both themes, e.g. `bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300`.

## 3. Components (`resources/views/components/`)

Use these before writing new markup.

- `<x-page-header title description eyebrow back back-label>`: the h1 of every staff page. Put buttons in `<x-slot:actions>`. Default slot renders under the description (tabs, badges). `back` is a URL.
- `<x-card title description :padding="true">`: section wrapper. Slots: `actions` (heading row), `footer`. Use `:padding="false"` around tables and lists.
- `<x-empty-state icon title description>`: default slot is the next action (a primary `flux:button`). Icon is a Heroicon name (`user-group`, `calendar-days`...).
- `<x-stat-tile label value hint icon tone>`: tone is `default`, `brand`, `amber` or `red`. Use in a `grid grid-cols-2 lg:grid-cols-4 gap-4`.
- `<x-theme-toggle>`: sun/moon button (`data-theme-toggle`, `aria-pressed`). Already in the app shell, auth layouts and public layout. Add it only to new standalone layouts. Pass classes to restyle.
- `<x-brand size="sm|md|lg" :href :wordmark>`: logo lockup for auth, public and marketing pages. `<x-app-logo>` is the Flux sidebar/header brand. `<x-app-logo-icon>` is the bare ball mark (uses `currentColor`).

Example:

```blade
<x-page-header :title="__('Players')" :description="__('Everyone who plays at your club.')">
    <x-slot:actions>
        <flux:button variant="primary" icon="plus">{{ __('Add player') }}</flux:button>
    </x-slot:actions>
</x-page-header>

<x-card :padding="false">...table...</x-card>
```

## 4. Layout, spacing, type

- Page content: inside `<flux:main>` (the app layout already does this). Stack sections with `space-y-6` (`lg:space-y-8`). Card padding `p-5`. Grid gaps `gap-4`.
- Radius: cards `rounded-2xl`, buttons and inputs `rounded-lg`/`rounded-xl`, chips `rounded-full`. Shadows: `shadow-xs` on cards, `shadow-sm` on auth cards, `shadow-xl` only on floating menus.
- Type scale: page title `text-2xl sm:text-3xl font-bold tracking-tight`; section title `text-base font-semibold`; body `text-sm`/`text-base`; small label `text-xs font-semibold uppercase tracking-wider`. Numbers: `tabular-nums`.
- Tap targets are at least 40px (`size-10`) and 44px on the organizer board. Actions that matter use `flux:button` `size="base"` or larger on touch screens.
- Mobile first: single column by default, add `sm:`/`lg:` for columns. Tables go in a horizontally scrollable wrapper or collapse to stacked rows on phones.
- Navigation: the sidebar groups are "Open play" (Sessions, Players, Stats), "Club" (Overview, Members, Settings) and "Platform" (Dashboard). New club pages join one of these groups. Each page has one `<x-page-header>` and, for sub-pages, a `back` link.

## 5. Do and don't

Do
- Keep every `data-test` attribute and the strings tests assert on.
- Give lists an `<x-empty-state>` that points to the next step.
- Use Flux for forms, buttons, modals, dropdowns. Use plain Tailwind for layout.
- Add `aria-label` to icon-only buttons, and keep `focus-visible` rings visible.
- Check both themes and a 375px viewport.

Don't
- Don't add a colour without a dark variant, or use raw hex values in views.
- Don't use `text-ball` or other light colours for text on light backgrounds.
- Don't put domain logic in views or components.
- Don't add `@fluxAppearance`, `class="dark"` on `<html>`, or `prefers-color-scheme` scripts.
- Don't make the TV page theme-dependent: it stays dark and read-only.

## 6. Results and share (Phase 11)

The results design is shared by the staff page (`Livewire/Sessions/ResultsPage`) and the public ended page (`Livewire/Public/Queue`). Both render `<x-results.body>` so they look identical.

- `<x-results.body :results :standings staff live>`: the whole layout. Slots: `neighbours` (prev/next) and `share` (the share panel or a note). `results` is the `SessionResultsService` array.
- `<x-results.hero>`: always a dark brand gradient (`from-brand-700 to-brand-950`) with white text, in both themes, so a screenshot looks the same. Holds club, session, date, four headline numbers and the confetti. Its slot is the podium.
- `<x-results.podium :podium>`: 2nd left, 1st centre and tallest, 3rd right. Medal and height come from the rank, so ties look the same. Fewer than 3 players give a smaller podium, none gives an empty state.
- `<x-results.highlights>`: cards for most games, best point diff, closest match, biggest win. Missing ones are hidden.
- `<x-results.share-panel>`: copy link (`x-copy-field`, which has a plain-HTTP fallback), native share, Facebook / X / WhatsApp / Messenger (Messenger shows on phones only), QR, GIF preview, Download GIF and Download image (`?download=1`).
- `<x-results.neighbours>`: previous / next / all sessions links. `<x-public.club-header :club-name :slug active>`: the public club header with Sessions and Leaderboard tabs.
- Motion is CSS only (`resources/css/app.css`, `.rs-rise`, `.rs-drop`, `.rs-fade-up`, `.rs-confetti`). Elements are visible without animation, and `prefers-reduced-motion` turns it all off. Medal colours: amber (gold), zinc (silver), orange (bronze), always with a text label or rank number, never colour alone.
- Public pages push Open Graph and Twitter tags through `@push('head')` (the public layout has `@stack('head')`).

## 7. Stats tables (P11.6)

- `<x-stats.table :rows :show-rank :min-games :caption>` is used for session standings and both leaderboards. Ranks 1–3 get medal badges (amber, zinc, orange, the same as the podium) and a soft tinted row, and tied ranks share the medal. Each player has an initials avatar with a fixed colour per name (`App\Support\StatsPresenter`), win % has a thin bar, point diff is a green / red / neutral pill, W is green and L is red. With `show-rank` false (the unranked list), pass `min-games` to show "6 / 10 games".
- `<x-stats.match-log :matches :session-date :caption>`: court badge, score pill, the winning team bold with a "Won" marker (none for a tie, a missing score or a void match), and the losing side muted. Durations come from `StatsPresenter::duration()` ("1 h 5 min"). A match that finished on another day shows the date ("Oct 8 · 13:21").
- On a phone (≤ 640 px) nothing scrolls sideways: standings fold Played/W/L into a "W–L · N played" line, and the match log becomes stacked cards.
- `caption` is a small branding footer inside the card ("club · session · date · PickleQ+"), so a cropped screenshot still says where it's from. The results page sets it, and the leaderboards leave it off.
