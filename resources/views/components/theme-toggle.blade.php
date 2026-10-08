{{--
    Sun/moon theme toggle. Plain JS (see partials/appearance.blade.php), so it works
    on pages without Alpine or Flux. aria-pressed is true while dark mode is on.
    Props: none. Pass classes to restyle (e.g. class="text-white" on dark surfaces).
--}}
<button
    type="button"
    data-theme-toggle
    aria-pressed="false"
    aria-label="{{ __('Dark mode') }}"
    title="{{ __('Toggle light and dark mode') }}"
    {{ $attributes->class([
        'inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-zinc-600 transition',
        'hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white',
    ]) }}
>
    <svg class="size-5 dark:hidden" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
    </svg>
    <svg class="hidden size-5 dark:block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
    </svg>
</button>
