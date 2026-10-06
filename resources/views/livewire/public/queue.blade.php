@php
    $live = $data['status'] === 'live';
@endphp

<main
    class="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-5 px-4 py-5"
    x-data="queueMe(@js($publicId))"
    wire:poll.30s.visible
    data-test="queue-page"
>
    <header>
        <h1 class="text-2xl font-bold" data-test="queue-title">{{ $data['name'] }}</h1>
        @if ($data['status'] === 'draft')
            <p class="text-zinc-600 dark:text-zinc-300">{{ __('Not started yet. Check back soon.') }}</p>
        @elseif ($data['status'] === 'ended')
            <p class="text-zinc-600 dark:text-zinc-300">{{ __('Session ended. Thanks for playing!') }}</p>
        @else
            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Live. This page updates by itself.') }}</p>
        @endif
    </header>

    {{-- "You're up next" / "Go to court N" banner --}}
    <div x-show="banner" x-cloak role="alert" class="rounded-xl bg-green-600 p-4 text-white shadow-lg" data-test="alert-banner">
        <p class="text-2xl font-bold" x-text="banner?.title"></p>
        <p x-text="banner?.body"></p>
    </div>

    @if ($live)
        {{-- Me card --}}
        <section class="rounded-xl border border-zinc-300 p-4 dark:border-zinc-700" aria-label="{{ __('You') }}" data-test="me-card">
            <template x-if="me === null">
                <details>
                    <summary class="cursor-pointer rounded-lg bg-zinc-900 px-4 py-3 text-center text-lg font-semibold text-white dark:bg-white dark:text-zinc-900" data-test="this-is-me">{{ __('This is me') }}</summary>
                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">{{ __('Tap your name. It is remembered on this device only.') }}</p>
                    <ul class="mt-2 max-h-72 divide-y divide-zinc-200 overflow-y-auto rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($data['players'] as $player)
                            <li wire:key="pick-{{ $player['id'] }}">
                                <button type="button" class="block w-full px-4 py-3 text-start text-lg hover:bg-zinc-100 dark:hover:bg-zinc-800" x-on:click="pick(@js($player['id']))" data-test="pick-player">{{ $player['name'] }}</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            </template>

            <template x-if="me !== null">
                <div class="space-y-2">
                    <template x-if="state?.kind === 'waiting'">
                        <p class="text-xl font-semibold" data-test="me-waiting">
                            <span x-text="`You're #${state.position}`"></span><span x-show="state.estimate !== null" x-text="`, about ${state.estimate} min`"></span>
                        </p>
                    </template>
                    <template x-if="state?.kind === 'upnext'"><p class="text-xl font-semibold" data-test="me-upnext">{{ __("You're up next!") }}</p></template>
                    <template x-if="state?.kind === 'court'"><p class="text-xl font-semibold" data-test="me-court" x-text="`Go to court ${state.court}`"></p></template>
                    <template x-if="state?.kind === 'break'"><p class="text-xl font-semibold">{{ __("You're on a break.") }}</p></template>
                    <template x-if="state === null"><p class="text-zinc-600 dark:text-zinc-300">{{ __("You're not in the queue right now.") }}</p></template>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" class="rounded-lg border border-zinc-400 px-4 py-2 font-medium" x-on:click="clear()" data-test="not-me">{{ __('Not me') }}</button>
                        <button type="button" x-show="alertsSupported && permission === 'default'" x-cloak class="rounded-lg bg-blue-600 px-4 py-2 font-medium text-white" x-on:click="enableAlerts()" data-test="enable-alerts">{{ __('Enable alerts') }}</button>
                        <span x-show="permission === 'granted'" x-cloak class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Alerts on while this page is open.') }}</span>
                        <span x-show="permission === 'denied' && alertsSupported" x-cloak class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Notifications are blocked in this browser.') }}</span>
                    </div>
                </div>
            </template>
        </section>

        <section aria-labelledby="courts-h" data-test="queue-courts">
            <h2 id="courts-h" class="mb-2 text-xl font-bold">{{ __('Courts') }}</h2>
            <ul class="space-y-2">
                @foreach ($data['courts'] as $court)
                    <li class="rounded-lg border border-zinc-300 p-3 dark:border-zinc-700" wire:key="court-{{ $court['court'] }}" data-test="queue-court">
                        <div class="flex items-baseline justify-between">
                            <span class="font-semibold">{{ __('Court :n', ['n' => $court['court']]) }}</span>
                            @if ($court['match'] && $court['match']['elapsed_minutes'] !== null)
                                <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $court['match']['elapsed_minutes'] }} {{ __('min') }}</span>
                            @endif
                        </div>
                        @if ($court['match'])
                            <p class="text-lg">
                                @foreach ($court['match']['teams']['A'] as $p)<x-public.player :id="$p['id']" :name="$p['name']" />{{ $loop->last ? '' : ' & ' }}@endforeach
                                <span class="text-zinc-500">{{ __('vs') }}</span>
                                @foreach ($court['match']['teams']['B'] as $p)<x-public.player :id="$p['id']" :name="$p['name']" />{{ $loop->last ? '' : ' & ' }}@endforeach
                            </p>
                        @else
                            <p class="text-zinc-500">{{ __('Open') }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="upnext-h" data-test="queue-up-next">
            <h2 id="upnext-h" class="mb-2 text-xl font-bold">{{ __('Up next') }}</h2>
            <ul class="space-y-2">
                @forelse ($data['up_next'] as $i => $match)
                    <li class="rounded-lg border border-amber-400 p-3 text-lg" wire:key="upnext-{{ $i }}">
                        @foreach ($match['teams']['A'] as $p)<x-public.player :id="$p['id']" :name="$p['name']" />{{ $loop->last ? '' : ' & ' }}@endforeach
                        <span class="text-zinc-500">{{ __('vs') }}</span>
                        @foreach ($match['teams']['B'] as $p)<x-public.player :id="$p['id']" :name="$p['name']" />{{ $loop->last ? '' : ' & ' }}@endforeach
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('Nobody staged yet.') }}</li>
                @endforelse
            </ul>
        </section>

        <section aria-labelledby="waiting-h" data-test="queue-waiting">
            <h2 id="waiting-h" class="mb-2 text-xl font-bold">{{ __('Waiting (:count)', ['count' => count($data['waiting'])]) }}</h2>
            <ol class="divide-y divide-zinc-200 rounded-lg border border-zinc-300 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($data['waiting'] as $row)
                    <li class="flex items-baseline justify-between gap-3 px-3 py-2 text-lg" wire:key="waiting-{{ $row['id'] }}">
                        <span><span class="inline-block w-8 tabular-nums text-zinc-500">{{ $row['position'] }}.</span><x-public.player :id="$row['id']" :name="$row['name']" /></span>
                        @if ($row['estimate_minutes'] !== null)
                            <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">~{{ $row['estimate_minutes'] }} {{ __('min') }}</span>
                        @endif
                    </li>
                @empty
                    <li class="px-3 py-3 text-zinc-500">{{ __('Nobody is waiting.') }}</li>
                @endforelse
            </ol>
        </section>

        @if ($data['on_break'])
            <section aria-labelledby="break-h" data-test="queue-break">
                <h2 id="break-h" class="mb-2 text-xl font-bold">{{ __('On break') }}</h2>
                <p class="text-lg">
                    @foreach ($data['on_break'] as $p)<x-public.player :id="$p['id']" :name="$p['name']" />{{ $loop->last ? '' : ', ' }}@endforeach
                </p>
            </section>
        @endif
    @endif
</main>
