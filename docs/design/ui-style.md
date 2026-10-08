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
