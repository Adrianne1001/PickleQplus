@php
    $live = $data['status'] === 'live';
    $groups = $data['groups'] ?? [];
    $courtGroup = [];
    foreach ($groups as $g) {
        foreach ($g['courts'] as $n) {
            $courtGroup[$n] = $g['label'];
        }
    }
    // Skill courts: sections per group; waiting positions count within each group.
    $upNextSections = $groups === []
        ? [['label' => null, 'matches' => $data['up_next']]]
        : array_map(fn (array $g): array => ['label' => $g['label'], 'matches' => array_values(array_filter($data['up_next'], fn (array $m): bool => $m['group'] === $g['index']))], $groups);
    $waitingSections = [];
    foreach ($data['waiting'] as $row) {
        $key = $groups === [] ? 0 : ($row['group'] ?? 0);
        $waitingSections[$key][] = [...$row, 'shown_position' => $row['group_position'] ?? $row['position']];
    }
    ksort($waitingSections);
    $groupLabel = collect($groups)->pluck('label', 'index')->all();
@endphp

@if ($results !== null)
    @push('head')
        <meta property="og:type" content="website" />
        <meta property="og:site_name" content="{{ config('app.name', 'PickleQ+') }}" />
        <meta property="og:title" content="{{ $shareTitle }}" />
        <meta property="og:description" content="{{ $ogDescription }}" />
        <meta property="og:url" content="{{ $shareUrl }}" />
        @if ($pngUrl)
            <meta property="og:image" content="{{ $pngUrl }}" />
            <meta property="og:image:type" content="image/png" />
            <meta property="og:image:width" content="1080" />
            <meta property="og:image:height" content="1080" />
            <meta property="og:image:alt" content="{{ $ogDescription }}" />
        @endif
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="{{ $shareTitle }}" />
        <meta name="twitter:description" content="{{ $ogDescription }}" />
        @if ($pngUrl)
            <meta name="twitter:image" content="{{ $pngUrl }}" />
        @endif
    @endpush

    <main class="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-5 px-4 pb-24 pt-5" data-test="queue-page">
        <x-public.club-header :club-name="$club->name" :slug="$club->slug" active="sessions" />
        <p class="sr-only">{{ __('Session ended. Thanks for playing!') }}</p>

        <x-results.body :results="$results" :standings="$results['standings']">
            @if ($previous || $next)
                <x-slot:neighbours>
                    <x-results.neighbours :previous="$previous" :next="$next" :all-url="route('public.sessions', $club->slug)" :all-label="__('All sessions')" />
                </x-slot:neighbours>
            @else
                <x-slot:neighbours>
                    <x-results.neighbours :all-url="route('public.sessions', $club->slug)" :all-label="__('All sessions')" />
                </x-slot:neighbours>
            @endif

            <x-slot:share>
                <x-results.share-panel :share-url="$shareUrl" :share-svg="$shareSvg" :title="$shareTitle" :text="$shareTitle.($podium === [] ? '' : ' '.$ogDescription)" :gif-url="$gifUrl" :png-url="$pngUrl" />
            </x-slot:share>
        </x-results.body>
    </main>
@else
<main
    class="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-6 px-4 pb-24 pt-5"
    x-data="queueMe(@js($publicId))"
    @if ($data['status'] !== 'ended') wire:poll.30s.visible @endif
    data-test="queue-page"
>
    <header class="space-y-2" x-data="{ shareOpen: false, copied: false, canShare: typeof navigator.share === 'function' }">
        <div class="flex items-center justify-between gap-3">
            <x-brand size="sm" />
            <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800"
                x-on:click="shareOpen = ! shareOpen" x-bind:aria-expanded="shareOpen" aria-controls="share-queue-panel" data-test="share-queue-button">
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="3" width="5.5" height="5.5" rx="1"/><rect x="3" y="11.5" width="5.5" height="5.5" rx="1"/><path d="M11.5 11.5h2.5v2.5m3 0v3h-3m-2.5 0v-1" stroke-linecap="round"/></svg>
                {{ __('Share') }}
            </button>
        </div>
        <div id="share-queue-panel" x-show="shareOpen" x-cloak class="rounded-2xl border border-zinc-200 bg-white p-5 text-center shadow-xs dark:border-zinc-700 dark:bg-zinc-900" data-test="share-queue-panel">
            <div class="mx-auto w-full max-w-64 rounded-xl bg-white p-3 text-zinc-900 shadow-sm ring-1 ring-zinc-200 [&>svg]:h-auto [&>svg]:w-full" data-test="public-queue-qr" role="img" aria-label="{{ __('QR code for this queue') }}">{!! $shareSvg !!}</div>
            <p class="mt-3 font-semibold text-zinc-900 dark:text-white">{{ __('Scan to follow this queue') }}</p>
            <input type="text" readonly value="{{ $shareUrl }}" x-ref="shareUrl" x-on:focus="$el.select()" aria-label="{{ __('Queue link') }}" class="mt-2 w-full rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-2 text-center text-xs text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" data-test="queue-share-url" />
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                <button type="button" class="min-h-11 rounded-xl bg-brand-700 px-4 py-2 font-semibold text-white hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    x-on:click="$refs.shareUrl.focus(); $refs.shareUrl.select(); try { (navigator.clipboard ? navigator.clipboard.writeText($refs.shareUrl.value) : Promise.resolve(document.execCommand('copy'))).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {}) } catch (e) {}" data-test="copy-queue-link">
                    <span x-show="!copied">{{ __('Copy link') }}</span><span x-show="copied" x-cloak role="status">{{ __('Copied') }}</span>
                </button>
                <button type="button" x-show="canShare" x-cloak class="min-h-11 rounded-xl border border-zinc-300 px-4 py-2 font-medium hover:bg-zinc-100 dark:border-zinc-600 dark:hover:bg-zinc-800"
                    x-on:click="navigator.share({ title: @js($data['name']), url: @js($shareUrl) }).catch(() => {})" data-test="native-share">{{ __('Share…') }}</button>
            </div>
        </div>
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl" data-test="queue-title">{{ $data['name'] }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if ($live)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-800 dark:bg-brand-950 dark:text-brand-300">
                    <span class="size-2 animate-pulse rounded-full bg-brand-600 dark:bg-brand-400" aria-hidden="true"></span>{{ __('Live') }}
                </span>
            @endif
            @if (($data['mode'] ?? 'balanced') === 'mixed')
                <span class="inline-block rounded-full bg-purple-100 px-3 py-1 text-xs font-semibold text-purple-900 dark:bg-purple-900 dark:text-purple-100" data-test="mode-label">{{ __('Mixed doubles') }}</span>
            @endif
            @if (($data['mode'] ?? 'balanced') === 'skill_courts')
                <span class="inline-block rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-900 dark:text-amber-100" data-test="mode-label">{{ __('Skill courts') }}</span>
            @endif
            @if (($data['mode'] ?? 'balanced') === 'social')
                <span class="inline-block rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-900 dark:bg-sky-900 dark:text-sky-100" data-test="mode-label">{{ __('Social mix') }}</span>
            @endif
        </div>
        @if ($data['status'] === 'draft')
            <p class="text-zinc-600 dark:text-zinc-400">{{ __('Not started yet. Check back soon.') }}</p>
        @elseif ($data['status'] === 'ended')
            <p class="text-zinc-600 dark:text-zinc-400">{{ __('Session ended. Thanks for playing!') }}</p>
        @else
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Live. This page updates by itself.') }}</p>
        @endif
    </header>

    {{-- "You're up next" / "Go to court N" banner --}}
    <div x-show="banner" x-cloak role="alert" class="rounded-2xl bg-brand-700 p-5 text-white shadow-lg ring-4 ring-brand-300 dark:ring-brand-500/40" data-test="alert-banner">
        <p class="text-3xl font-extrabold tracking-tight" x-text="banner?.title"></p>
        <p class="mt-1 text-lg" x-text="banner?.body"></p>
    </div>

    @if ($live)
        {{-- Me card: the hero of the page --}}
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('You') }}" data-test="me-card">
            <template x-if="me === null">
                <details>
                    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-center rounded-xl bg-brand-700 px-4 py-3 text-center text-lg font-bold text-white shadow-xs hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600" data-test="this-is-me">{{ __('This is me') }}</summary>
                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Tap your name. It is remembered on this device only.') }}</p>
                    <ul class="mt-2 max-h-72 divide-y divide-zinc-200 overflow-y-auto rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($data['players'] as $player)
                            <li wire:key="pick-{{ $player['id'] }}">
                                <button type="button" class="block min-h-12 w-full px-4 py-3 text-start text-lg hover:bg-zinc-100 dark:hover:bg-zinc-800" x-on:click="pick(@js($player['id']))" data-test="pick-player">{{ $player['name'] }}</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            </template>

            <template x-if="me !== null">
                <div class="space-y-4">
                    <template x-if="state?.kind === 'waiting'">
                        <div class="rounded-xl bg-sky-50 p-4 text-sky-900 dark:bg-sky-950 dark:text-sky-100" data-test="me-waiting">
                            <p class="text-xs font-semibold uppercase tracking-wider">{{ __('Your place in line') }}</p>
                            <p class="mt-1 text-5xl font-extrabold tabular-nums" x-text="`#${state.position}`"></p>
                            <p class="mt-1 text-lg font-medium" x-show="state.estimate !== null" x-text="`about ${state.estimate} min`"></p>
                        </div>
                    </template>
                    <template x-if="state?.kind === 'upnext'"><p class="rounded-xl bg-amber-100 p-4 text-3xl font-extrabold text-amber-900 dark:bg-amber-900 dark:text-amber-50" data-test="me-upnext">{{ __("You're up next!") }}</p></template>
                    <template x-if="state?.kind === 'court'"><p class="rounded-xl bg-brand-700 p-4 text-3xl font-extrabold text-white" data-test="me-court" x-text="`Go to court ${state.court}`"></p></template>
                    <template x-if="state?.kind === 'break'"><p class="rounded-xl bg-zinc-100 p-4 text-2xl font-bold text-zinc-900 dark:bg-zinc-800 dark:text-white">{{ __("You're on a break.") }}</p></template>
                    <template x-if="state === null"><p class="rounded-xl bg-zinc-100 p-4 text-lg text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ __("You're not in the queue right now.") }}</p></template>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" class="min-h-11 rounded-xl border border-zinc-300 px-4 py-2 font-medium hover:bg-zinc-100 dark:border-zinc-600 dark:hover:bg-zinc-800" x-on:click="clear()" data-test="not-me">{{ __('Not me') }}</button>
                        <button type="button" x-show="alertsSupported && permission === 'default'" x-cloak class="min-h-11 rounded-xl bg-brand-700 px-4 py-2 font-semibold text-white hover:bg-brand-800" x-on:click="enableAlerts()" data-test="enable-alerts">{{ __('Enable alerts') }}</button>
                        <span x-show="permission === 'granted'" x-cloak class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Alerts on while this page is open.') }}</span>
                        <span x-show="permission === 'denied' && alertsSupported" x-cloak class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Notifications are blocked in this browser.') }}</span>
                    </div>
                </div>
            </template>
        </section>

        <section aria-labelledby="courts-h" data-test="queue-courts">
            <h2 id="courts-h" class="mb-3 text-lg font-bold">{{ __('Courts') }}</h2>
            <ul class="space-y-3">
                @foreach ($data['courts'] as $court)
                    <li wire:key="court-{{ $court['court'] }}" data-test="queue-court">
                        <x-public.match-board
                            :teams="$court['match']['teams'] ?? []"
                            :title="__('Court :n', ['n' => $court['court']])"
                            :group="$courtGroup[$court['court']] ?? null"
                            :elapsed="$court['match']['elapsed_minutes'] ?? null"
                            :open="! $court['match']"
                        />
                    </li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="upnext-h" data-test="queue-up-next">
            <h2 id="upnext-h" class="mb-3 text-lg font-bold">{{ __('Up next') }}</h2>
            @foreach ($upNextSections as $section)
                @if ($section['label'] !== null)
                    <h3 class="mb-2 mt-3 text-xs font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-300" data-test="queue-group-label">{{ $section['label'] }}</h3>
                @endif
                <ul class="space-y-2">
                    @forelse ($section['matches'] as $i => $match)
                        <li wire:key="upnext-{{ $section['label'] }}-{{ $i }}">
                            <x-public.match-board :teams="$match['teams']" :title="$i === 0 ? __('Next') : __('Match :n', ['n' => $i + 1])" compact />
                        </li>
                    @empty
                        <li class="text-zinc-600 dark:text-zinc-400">{{ __('Nobody staged yet.') }}</li>
                    @endforelse
                </ul>
            @endforeach
        </section>

        <section aria-labelledby="waiting-h" data-test="queue-waiting">
            <h2 id="waiting-h" class="mb-3 text-lg font-bold">{{ __('Waiting (:count)', ['count' => count($data['waiting'])]) }}</h2>
            @forelse ($waitingSections as $key => $rows)
                @if ($groups !== [] && isset($groupLabel[$key]))
                    <h3 class="mb-2 mt-3 text-xs font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-300" data-test="queue-group-label">{{ $groupLabel[$key] }}</h3>
                @endif
                <ol class="divide-y divide-zinc-200 overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach ($rows as $row)
                        <li class="flex items-center justify-between gap-3 px-4 py-3 text-lg" wire:key="waiting-{{ $row['id'] }}">
                            <span class="flex items-center gap-3">
                                <span data-test="waiting-position" class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-bold tabular-nums text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $row['shown_position'] }}</span>
                                <x-public.player :id="$row['id']" :name="$row['name']" />
                            </span>
                            @if ($row['estimate_minutes'] !== null)
                                <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-400">~{{ $row['estimate_minutes'] }} {{ __('min') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @empty
                <div class="rounded-2xl border border-dashed border-zinc-300 px-4 py-6 text-center text-zinc-600 dark:border-zinc-700 dark:text-zinc-400">{{ __('Nobody is waiting.') }}</div>
            @endforelse
        </section>

        @if ($data['on_break'])
            <section aria-labelledby="break-h" data-test="queue-break">
                <h2 id="break-h" class="mb-2 text-lg font-bold">{{ __('On break') }}</h2>
                <ul class="flex flex-wrap gap-x-4 gap-y-2 text-lg text-zinc-700 dark:text-zinc-300">
                    @foreach ($data['on_break'] as $p)
                        <li class="flex items-center gap-1.5"><x-public.player :id="$p['id']" :name="$p['name']" /></li>
                    @endforeach
                </ul>
            </section>
        @endif
        <section aria-labelledby="players-h" data-test="queue-players">
            <h2 id="players-h" class="mb-3 text-lg font-bold">{{ __('Players (:count)', ['count' => count($data['players'])]) }}</h2>
            <ul class="divide-y divide-zinc-200 overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
                @forelse ($data['players'] as $p)
                    <li class="flex items-center justify-between gap-3 px-4 py-3 text-lg" wire:key="player-{{ $p['id'] }}">
                        <span class="min-w-0 [overflow-wrap:anywhere]"><x-public.player :id="$p['id']" :name="$p['name']" /></span>
                        <span class="shrink-0 text-sm text-zinc-600 dark:text-zinc-400"><span data-test="player-games">{{ trans_choice(':count game|:count games', $p['games_played'] ?? 0) }}</span> · <span data-test="player-wins">{{ trans_choice(':count win|:count wins', $p['wins'] ?? 0) }}</span></span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-zinc-600 dark:text-zinc-400">{{ __('Nobody has checked in yet.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif
</main>
@endif
