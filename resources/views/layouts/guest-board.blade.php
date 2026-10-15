{{--
    Layout for guest (no account) board pages: board, edit and results (Phase 14).
    No sidebar and no user menu. Top bar: brand, "Guest session" badge, theme toggle and the
    claim link. Optional data: `club` (falls back to the request's club), `title`.
--}}
@php
    $club = ($club ?? null) ?: \App\Models\Club::current();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <meta name="robots" content="noindex, nofollow" />
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <a href="#main" class="sr-only z-50 rounded-lg bg-white px-4 py-2 font-semibold text-zinc-900 focus:not-sr-only focus:fixed focus:start-4 focus:top-4">{{ __('Skip to content') }}</a>

        <header class="board-chrome sticky top-0 z-30 border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/90" data-test="guest-topbar">
            {{-- Phones: brand mark only, and the badge wraps onto its own row, so nothing overflows at 320 px. --}}
            <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-2 gap-y-1.5 px-4 py-2.5 sm:flex-nowrap sm:gap-x-3 sm:px-6 lg:px-8">
                <x-brand size="sm" :href="route('home')" class="max-sm:[&>span:last-child]:sr-only" />

                <div class="order-last basis-full sm:order-none sm:basis-auto">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:ring-amber-900" data-test="guest-badge">
                        <span class="size-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                        {{ __('Guest session') }}
                    </span>
                </div>

                <div class="ms-auto flex min-w-0 items-center gap-1.5 sm:gap-2">
                    @if ($club)
                        <a
                            href="{{ route('guest.claim', $club) }}"
                            class="inline-flex min-h-10 items-center justify-center rounded-xl bg-brand-700 px-3 py-2 text-center text-xs font-semibold leading-tight text-white shadow-xs hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:px-4 sm:text-sm"
                            aria-label="{{ __('Sign up to keep this session') }}"
                            data-test="guest-claim-link"
                        >
                            <span class="sm:hidden">{{ __('Keep session') }}</span>
                            <span class="hidden sm:inline">{{ __('Sign up to keep this session') }}</span>
                        </a>
                    @endif
                    <x-theme-toggle />
                </div>
            </div>
        </header>

        <main id="main" class="board-fs-wide mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8 [&>section]:mx-auto">
            {{ $slot }}
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
