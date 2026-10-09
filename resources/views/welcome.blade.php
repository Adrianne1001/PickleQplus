@php
    $canLogin = Route::has('login');
    $canRegister = Route::has('register');

    $navLinks = [['#features', 'Features'], ['#how-it-works', 'How it works'], ['#modes', 'Rotation modes'], ['#faq', 'FAQ']];

    $features = [
        ['icon' => 'scale', 'title' => 'Fair, balanced rotation', 'text' => 'Players with the fewest games go first, then whoever has waited longest. Teams are split so each match is as even as it can be.'],
        ['icon' => 'tv', 'title' => 'Live TV display', 'text' => 'Put courts, Up Next and the waiting list on a big screen. It is large, high contrast and updates the moment the board changes.'],
        ['icon' => 'qr-code', 'title' => 'QR self check-in', 'text' => 'Players scan a code, search their name and tap it. No app, no login, and organizers can still add or remove anyone.'],
        ['icon' => 'device-phone-mobile', 'title' => 'Live queue on phones', 'text' => 'A public page shows who is playing, who is next and an estimated wait, so nobody has to crowd around the board.'],
        ['icon' => 'bell-alert', 'title' => '"You\'re up next" alerts', 'text' => 'The queue page flags a player as soon as they are in the next match, so they are warmed up and ready.'],
        ['icon' => 'chart-bar', 'title' => 'Stats and leaderboard', 'text' => 'Every finished match feeds player profiles, win rates, streaks and a club leaderboard you can share.'],
        ['icon' => 'arrow-down-tray', 'title' => 'DUPR CSV export', 'text' => 'Export a session in DUPR\'s upload template so rated play is a download away, not a spreadsheet job.'],
    ];

    $steps = [
        ['title' => 'Create your club and session', 'text' => 'Add your courts and players, then open a session for tonight\'s open play.'],
        ['title' => 'Players check in', 'text' => 'Show the QR code. Players find their name and tap it, or staff check them in from the board.'],
        ['title' => 'Run the courts', 'text' => 'The board stages the next match. Start it, enter the score and the court refills from the queue.'],
        ['title' => 'Everyone stays in the loop', 'text' => 'The TV and phones update live. Afterwards, check stats or export results for DUPR.'],
    ];

    $faqs = [
        ['q' => 'Is PickleQ+ really free?', 'a' => 'Yes. It is free and self-hosted, so you run it on your own server. There are no plans, seats or per-court fees.'],
        ['q' => 'Do players need an account?', 'a' => 'No. Players check in through a QR code by tapping their name, and the public queue page needs no login. Only organizers sign in.'],
        ['q' => 'How are matches chosen?', 'a' => 'Waiting players are ordered by fewest games played, then longest wait. From the top of the list the engine picks the group and team split that is most evenly matched.'],
        ['q' => 'Can I use it on a phone or tablet?', 'a' => 'Yes. The organizer board is built for a phone or tablet next to the court, and the public pages are mobile first.'],
        ['q' => 'What about DUPR?', 'a' => 'You can export a session as a CSV in DUPR\'s upload format. Direct submission through DUPR\'s API is only planned if DUPR grants access.'],
    ];

    $modes = [
        ['name' => 'Balanced', 'text' => 'The default. Fewest games first, then longest wait, with teams split for an even match.', 'ready' => true],
        ['name' => 'Mixed doubles', 'text' => 'Every team is one man and one woman. If the queue does not have the right mix, the slot waits.', 'ready' => true],
        ['name' => 'Skill courts', 'text' => 'Split courts into star-rated groups so players meet others at their level, each with its own queue.', 'ready' => true],
        ['name' => 'Social mix', 'text' => 'Rotates partners before repeating, then spreads opponents as fairly as possible. Ratings aren\'t used.', 'ready' => true],
        ['name' => 'Winners stay', 'text' => 'Winners hold the court for a set number of wins while challengers come from the queue.', 'ready' => false],
        ['name' => 'King of the Court', 'text' => 'A rolling ladder: winners move up a court, losers move down.', 'ready' => false],
    ];

    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-5 py-3 text-base font-semibold text-white shadow-sm transition hover:bg-brand-800';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-300 bg-white px-5 py-3 text-base font-semibold text-zinc-900 shadow-xs transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800';
    $btnWhite = 'inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-base font-semibold text-brand-900 shadow-sm transition hover:bg-brand-50';
    $btnGhostOnDark = 'inline-flex items-center justify-center rounded-xl border border-white/40 px-6 py-3 text-base font-semibold text-white transition hover:bg-white/10';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Open-play pickleball queue manager'])
        <meta name="description" content="PickleQ+ is a free, self-hosted queue and court rotation manager for pickleball open play, with a live TV display, QR check-in and DUPR CSV export.">
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <a href="#main" class="sr-only z-50 rounded-lg bg-white px-4 py-2 font-semibold text-zinc-900 focus:not-sr-only focus:fixed focus:start-4 focus:top-4">Skip to content</a>

        {{-- Sticky nav --}}
        <header class="sticky top-0 z-40 border-b border-zinc-200/80 bg-white/85 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/85" data-test="landing-nav">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6">
                <x-brand size="sm" />

                <nav class="ms-6 hidden items-center gap-1 md:flex" aria-label="Sections">
                    @foreach ($navLinks as [$href, $label])
                        <a href="{{ $href }}" class="rounded-lg px-3 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white">{{ $label }}</a>
                    @endforeach
                </nav>

                <div class="ms-auto flex items-center gap-1.5 sm:gap-2">
                    <x-theme-toggle />

                    @auth
                        <a href="{{ route('dashboard') }}" class="hidden rounded-xl bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 sm:inline-flex" data-test="landing-dashboard">Go to dashboard</a>
                    @else
                        @if ($canLogin)
                            <a href="{{ route('login') }}" class="hidden rounded-xl px-3 py-2 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-100 sm:inline-flex dark:text-zinc-200 dark:hover:bg-zinc-800" data-test="landing-login">Log in</a>
                        @endif
                        @if ($canRegister)
                            <a href="{{ route('register') }}" class="hidden rounded-xl bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-800 sm:inline-flex" data-test="landing-register">Get started</a>
                        @endif
                    @endauth

                    {{-- Mobile menu (no JS needed) --}}
                    <details class="group relative md:hidden">
                        <summary class="flex size-10 cursor-pointer list-none items-center justify-center rounded-lg text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800 [&::-webkit-details-marker]:hidden" aria-label="Menu">
                            <svg class="size-6 group-open:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16" /></svg>
                            <svg class="hidden size-6 group-open:block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" /></svg>
                        </summary>
                        <div class="absolute end-0 mt-2 w-64 rounded-2xl border border-zinc-200 bg-white p-2 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                            @foreach ($navLinks as [$href, $label])
                                <a href="{{ $href }}" class="block rounded-lg px-3 py-2.5 text-sm font-medium text-zinc-800 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-zinc-800">{{ $label }}</a>
                            @endforeach
                            <div class="mt-2 grid gap-2 border-t border-zinc-100 pt-2 dark:border-zinc-800">
                                @auth
                                    <a href="{{ route('dashboard') }}" class="rounded-xl bg-brand-700 px-4 py-2.5 text-center text-sm font-semibold text-white">Go to dashboard</a>
                                @else
                                    @if ($canRegister)
                                        <a href="{{ route('register') }}" class="rounded-xl bg-brand-700 px-4 py-2.5 text-center text-sm font-semibold text-white">Get started</a>
                                    @endif
                                    @if ($canLogin)
                                        <a href="{{ route('login') }}" class="rounded-xl border border-zinc-300 px-4 py-2.5 text-center text-sm font-semibold text-zinc-800 dark:border-zinc-700 dark:text-zinc-100">Log in</a>
                                    @endif
                                @endauth
                            </div>
                        </div>
                    </details>
                </div>
            </div>
        </header>

        <main id="main">
            {{-- Hero --}}
            <section class="relative overflow-hidden">
                <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                    <div class="absolute -top-24 start-1/2 h-[28rem] w-[60rem] -translate-x-1/2 rounded-full bg-brand-200/50 blur-3xl dark:bg-brand-900/40"></div>
                    <div class="absolute -end-24 top-40 size-72 rounded-full bg-ball/30 blur-3xl dark:bg-ball/10"></div>
                </div>

                <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-2 lg:gap-10 lg:py-28">
                    <div>
                        <span class="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-800 dark:border-brand-800 dark:bg-brand-950 dark:text-brand-300">
                            <span class="size-1.5 rounded-full bg-brand-600 dark:bg-brand-400"></span>
                            Free and self-hosted
                        </span>
                        <h1 class="mt-5 text-4xl font-extrabold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                            Open play that runs itself.
                        </h1>
                        <p class="mt-5 max-w-xl text-lg text-zinc-600 dark:text-zinc-300">
                            PickleQ+ keeps the queue fair, fills courts the moment they free up and shows everyone what is next, on a TV and on their phones. You just play.
                        </p>
                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            @auth
                                <a href="{{ route('dashboard') }}" class="{{ $btnPrimary }}" data-test="hero-dashboard">Go to dashboard</a>
                            @else
                                @if ($canRegister)
                                    <a href="{{ route('register') }}" class="{{ $btnPrimary }}" data-test="hero-register">Get started, it's free</a>
                                @endif
                                @if ($canLogin)
                                    <a href="{{ route('login') }}" class="{{ $btnSecondary }}" data-test="hero-login">Log in</a>
                                @endif
                            @endauth
                        </div>
                        <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">No per-court fees. Players never need an account.</p>
                    </div>

                    {{-- Court board mock (illustration only) --}}
                    <div class="relative" role="img" aria-label="Illustration of the organizer board showing two courts in play, the next match and a waiting list with estimated waits">
                        <div class="rounded-3xl border border-zinc-200 bg-white p-4 shadow-2xl shadow-brand-900/10 sm:p-5 dark:border-zinc-700 dark:bg-zinc-900 dark:shadow-black/40" aria-hidden="true">
                            <div class="mb-4 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="size-2.5 rounded-full bg-brand-500"></span>
                                    <span class="text-sm font-bold">Tuesday open play</span>
                                </div>
                                <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-800 dark:bg-brand-950 dark:text-brand-300">Live</span>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ([['Court 1', '08:12', 'Alex & Sam', 'Jordan & Riley'], ['Court 2', '03:40', 'Casey & Morgan', 'Taylor & Jamie']] as [$court, $time, $a, $b])
                                    <div class="rounded-2xl border border-brand-200 bg-brand-50/60 p-3 dark:border-brand-900 dark:bg-brand-950/40">
                                        <div class="flex items-center justify-between text-xs font-semibold">
                                            <span class="text-brand-800 dark:text-brand-300">{{ $court }}</span>
                                            <span class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ $time }}</span>
                                        </div>
                                        <div class="mt-3 grid gap-1.5 text-sm font-semibold">
                                            <div class="rounded-lg bg-white px-2.5 py-2 shadow-xs dark:bg-zinc-800">{{ $a }}</div>
                                            <div class="text-center text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">vs</div>
                                            <div class="rounded-lg bg-white px-2.5 py-2 shadow-xs dark:bg-zinc-800">{{ $b }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-3 rounded-2xl border border-dashed border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/40">
                                <p class="text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">Up next</p>
                                <p class="mt-1 text-sm font-semibold">Drew &amp; Quinn <span class="text-zinc-500 dark:text-zinc-400">vs</span> Avery &amp; Blake</p>
                            </div>

                            <div class="mt-3 divide-y divide-zinc-100 rounded-2xl border border-zinc-200 text-sm dark:divide-zinc-800 dark:border-zinc-700">
                                @foreach ([['Parker', '0 games', '~4 min'], ['Reese', '1 game', '~9 min'], ['Skyler', '1 game', '~12 min']] as [$name, $games, $wait])
                                    <div class="flex items-center justify-between px-3 py-2">
                                        <span class="font-semibold">{{ $name }}</span>
                                        <span class="flex items-center gap-3 text-xs text-zinc-600 dark:text-zinc-400">
                                            <span>{{ $games }}</span>
                                            <span class="rounded-full bg-zinc-100 px-2 py-0.5 font-semibold tabular-nums text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ $wait }}</span>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Features --}}
            <section id="features" class="scroll-mt-20 border-t border-zinc-200 bg-zinc-50 py-16 sm:py-24 dark:border-zinc-800 dark:bg-zinc-900/40">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="text-sm font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-400">Features</p>
                        <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Everything open play needs, nothing it doesn't</h2>
                        <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-300">Built for the person running the courts and the players waiting for their turn.</p>
                    </div>

                    <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($features as $feature)
                            <article class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs transition hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900">
                                <span class="flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-400">
                                    <flux:icon :icon="$feature['icon']" class="size-6" />
                                </span>
                                <h3 class="mt-4 text-lg font-semibold">{{ $feature['title'] }}</h3>
                                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $feature['text'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- How it works --}}
            <section id="how-it-works" class="scroll-mt-20 py-16 sm:py-24">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="text-sm font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-400">How it works</p>
                        <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">From arriving to playing in four steps</h2>
                    </div>

                    <ol class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($steps as $i => $step)
                            <li class="rounded-2xl border border-zinc-200 p-6 dark:border-zinc-700">
                                <span class="flex size-10 items-center justify-center rounded-full bg-brand-700 text-base font-bold text-white">{{ $i + 1 }}</span>
                                <h3 class="mt-4 text-base font-semibold">{{ $step['title'] }}</h3>
                                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $step['text'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            {{-- Rotation modes --}}
            <section id="modes" class="scroll-mt-20 border-t border-zinc-200 bg-zinc-50 py-16 sm:py-24 dark:border-zinc-800 dark:bg-zinc-900/40">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <p class="text-sm font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-400">Rotation modes</p>
                        <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Pick the format that fits your night</h2>
                        <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-300">Choose a mode for each session. You can switch while it is live.</p>
                    </div>

                    <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($modes as $mode)
                            <article @class([
                                'rounded-2xl border p-6',
                                'border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900' => $mode['ready'],
                                'border-dashed border-zinc-300 bg-transparent dark:border-zinc-700' => ! $mode['ready'],
                            ])>
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-lg font-semibold">{{ $mode['name'] }}</h3>
                                    @if ($mode['ready'])
                                        <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-800 dark:bg-brand-950 dark:text-brand-300">Available</span>
                                    @else
                                        <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">Coming soon</span>
                                    @endif
                                </div>
                                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $mode['text'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- FAQ --}}
            <section id="faq" class="scroll-mt-20 py-16 sm:py-24">
                <div class="mx-auto max-w-3xl px-4 sm:px-6">
                    <div class="text-center">
                        <p class="text-sm font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-400">FAQ</p>
                        <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Questions, answered</h2>
                    </div>

                    <div class="mt-10 divide-y divide-zinc-200 rounded-2xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                        @foreach ($faqs as $faq)
                            <details class="group px-5 py-4">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-base font-semibold [&::-webkit-details-marker]:hidden">
                                    {{ $faq['q'] }}
                                    <svg class="size-5 shrink-0 text-zinc-500 transition group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg>
                                </summary>
                                <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $faq['a'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Final CTA --}}
            <section class="px-4 pb-16 sm:px-6 sm:pb-24">
                <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-brand-900 px-6 py-14 text-center text-white sm:px-12 sm:py-20">
                    <div class="pointer-events-none absolute -end-16 -top-16 size-64 rounded-full bg-ball/20 blur-3xl" aria-hidden="true"></div>
                    <h2 class="relative text-3xl font-bold tracking-tight text-balance sm:text-4xl">Spend less time on the board and more on the court</h2>
                    <p class="relative mx-auto mt-4 max-w-xl text-lg text-brand-100">Set up your club in a few minutes. It is free, and it is yours to host.</p>
                    <div class="relative mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        @auth
                            <a href="{{ route('dashboard') }}" class="{{ $btnWhite }}">Go to dashboard</a>
                        @else
                            @if ($canRegister)
                                <a href="{{ route('register') }}" class="{{ $btnWhite }}">Get started</a>
                            @endif
                            @if ($canLogin)
                                <a href="{{ route('login') }}" class="{{ $btnGhostOnDark }}">Log in</a>
                            @endif
                        @endauth
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-zinc-200 py-10 dark:border-zinc-800">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-6 px-4 sm:flex-row sm:px-6">
                <div class="flex flex-col items-center gap-2 sm:items-start">
                    <x-brand size="sm" />
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Free, self-hosted open-play queue manager.</p>
                </div>
                <nav class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm font-medium text-zinc-600 dark:text-zinc-400" aria-label="Footer">
                    <a href="#features" class="hover:text-zinc-900 dark:hover:text-white">Features</a>
                    <a href="#faq" class="hover:text-zinc-900 dark:hover:text-white">FAQ</a>
                    @auth
                        <a href="{{ route('dashboard') }}" class="hover:text-zinc-900 dark:hover:text-white">Dashboard</a>
                    @else
                        @if ($canLogin)
                            <a href="{{ route('login') }}" class="hover:text-zinc-900 dark:hover:text-white">Log in</a>
                        @endif
                        @if ($canRegister)
                            <a href="{{ route('register') }}" class="hover:text-zinc-900 dark:hover:text-white">Register</a>
                        @endif
                    @endauth
                </nav>
            </div>
            <p class="mt-6 text-center text-xs text-zinc-600 dark:text-zinc-400">&copy; {{ date('Y') }} PickleQ+</p>
        </footer>
    </body>
</html>
