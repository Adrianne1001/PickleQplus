@php
    $courtCount = count($data['courts']);
    // Denser grid and smaller type as the number of courts grows.
    [$cols, $nameSize, $numSize] = match (true) {
        $courtCount <= 2 => ['grid-cols-1', 'text-5xl', 'text-4xl'],
        $courtCount <= 4 => ['grid-cols-2', 'text-4xl', 'text-3xl'],
        $courtCount <= 6 => ['grid-cols-2 2xl:grid-cols-3', 'text-3xl', 'text-2xl'],
        $courtCount <= 12 => ['grid-cols-3 2xl:grid-cols-4', 'text-2xl', 'text-xl'],
        $courtCount <= 24 => ['grid-cols-4 2xl:grid-cols-6', 'text-lg', 'text-base'],
        default => ['grid-cols-6 2xl:grid-cols-8', 'text-sm', 'text-sm'],
    };
    $teamNames = fn (array $team): string => collect($team)->pluck('name')->implode(' & ');
    $waitingShown = array_slice($data['waiting'], 0, $waitingLimit);
    $waitingMore = max(0, count($data['waiting']) - $waitingLimit);
    $live = $data['status'] === 'live';
    $groups = $data['groups'] ?? [];
    $groupLabel = collect($groups)->pluck('label', 'index')->all();
    $courtGroup = [];
    foreach ($groups as $g) {
        foreach ($g['courts'] as $n) {
            $courtGroup[$n] = $g['label'];
        }
    }
    // Skill courts: sections per group; positions count within each group.
    $upNextSections = $groups === []
        ? [['label' => null, 'matches' => $data['up_next']]]
        : array_map(fn (array $g): array => ['label' => $g['label'], 'matches' => array_values(array_filter($data['up_next'], fn (array $m): bool => $m['group'] === $g['index']))], $groups);
    $waitingSections = [];
    foreach ($waitingShown as $row) {
        $key = $groups === [] ? 0 : ($row['group'] ?? 0);
        $waitingSections[$key][] = [...$row, 'shown_position' => $row['group_position'] ?? $row['position']];
    }
    ksort($waitingSections);
@endphp

<div
    class="flex h-screen w-screen flex-col overflow-hidden bg-zinc-950 p-6 text-white"
    wire:poll.15s.visible
    x-data="tvScreen()"
    data-test="tv"
>
    <header class="mb-4 flex items-center justify-between gap-6">
        <h1 class="truncate text-4xl font-bold tracking-tight" data-test="tv-title">{{ $data['name'] }}</h1>
        <div class="flex items-center gap-4">
            @if (($data['mode'] ?? 'balanced') === 'skill_courts')
                <span class="rounded-full border border-amber-400 px-4 py-1 text-xl font-semibold text-amber-200" data-test="mode-label">{{ __('Skill courts') }}</span>
            @endif
            @if (($data['mode'] ?? 'balanced') === 'mixed')
                <span class="rounded-full border border-purple-400 px-4 py-1 text-xl font-semibold text-purple-200" data-test="mode-label">{{ __('Mixed doubles') }}</span>
            @endif
            @if ($live)
                <span class="rounded-full bg-green-500 px-4 py-1 text-xl font-semibold text-black">{{ __('Live') }}</span>
            @endif
            <button type="button" x-on:click="toggleFullscreen()" x-show="supported" x-cloak class="rounded-md border border-zinc-600 px-3 py-1 text-sm text-zinc-300 hover:bg-zinc-800 focus:outline-2 focus:outline-white" data-test="fullscreen-button">
                {{ __('Full screen') }}
            </button>
        </div>
    </header>

    @if ($data['status'] === 'ended')
        <div class="flex flex-1 items-center justify-center" data-test="tv-ended">
            <p class="text-7xl font-bold text-zinc-300">{{ __('Session ended') }}</p>
        </div>
    @elseif ($data['status'] === 'draft')
        <div class="flex flex-1 flex-col items-center justify-center gap-10 text-center" data-test="tv-draft">
            <p class="text-8xl font-bold">{{ __('Starting soon') }}</p>
            @if ($svg)
                @include('livewire.public.partials.tv-qr', ['svg' => $svg, 'checkinUrl' => $checkinUrl, 'size' => 'large'])
            @endif
        </div>
    @else
        <div class="grid min-h-0 flex-1 grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-6">
            <section class="min-h-0 overflow-hidden" aria-label="{{ __('Courts') }}" data-test="tv-courts">
                <div class="grid h-full auto-rows-fr gap-3 {{ $cols }}">
                    @foreach ($data['courts'] as $court)
                        <div class="flex min-h-0 flex-col justify-center overflow-hidden rounded-xl border-2 p-3 {{ $court['match'] ? 'border-green-500 bg-zinc-900' : 'border-zinc-700 bg-zinc-950' }}" data-test="tv-court">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="{{ $numSize }} font-bold text-green-400">{{ __('Court :n', ['n' => $court['court']]) }}@if (isset($courtGroup[$court['court']]))<span class="ml-2 text-base font-medium text-zinc-400" data-test="tv-court-group">{{ $courtGroup[$court['court']] }}</span>@endif</span>
                                @if ($court['match'] && $court['match']['elapsed_minutes'] !== null)
                                    <span class="{{ $numSize }} tabular-nums text-zinc-300">{{ $court['match']['elapsed_minutes'] }} {{ __('min') }}</span>
                                @endif
                            </div>
                            @if ($court['match'])
                                <p class="{{ $nameSize }} mt-1 truncate font-semibold leading-tight">{{ $teamNames($court['match']['teams']['A']) }}</p>
                                <p class="{{ $numSize }} text-zinc-400">{{ __('vs') }}</p>
                                <p class="{{ $nameSize }} truncate font-semibold leading-tight">{{ $teamNames($court['match']['teams']['B']) }}</p>
                            @else
                                <p class="{{ $nameSize }} mt-1 text-zinc-500">{{ __('Open') }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            <aside class="flex min-h-0 flex-col gap-4 overflow-hidden">
                <section data-test="tv-up-next">
                    <h2 class="mb-2 text-3xl font-bold text-amber-300">{{ __('Up next') }}</h2>
                    @foreach ($upNextSections as $section)
                        @if ($section['label'] !== null)
                            <h3 class="mb-1 mt-2 text-xl font-semibold text-amber-200" data-test="tv-group-label">{{ $section['label'] }}</h3>
                        @endif
                        <ul class="space-y-2">
                            @forelse ($section['matches'] as $match)
                                <li class="rounded-lg bg-zinc-900 px-3 py-2 text-2xl leading-tight">
                                    <span class="font-semibold">{{ $teamNames($match['teams']['A']) }}</span>
                                    <span class="text-zinc-400">{{ __('vs') }}</span>
                                    <span class="font-semibold">{{ $teamNames($match['teams']['B']) }}</span>
                                </li>
                            @empty
                                <li class="text-2xl text-zinc-500">{{ __('Nobody staged yet') }}</li>
                            @endforelse
                        </ul>
                    @endforeach
                </section>

                <section class="min-h-0 flex-1 overflow-hidden" data-test="tv-waiting">
                    <h2 class="mb-2 text-3xl font-bold text-sky-300">{{ __('Waiting (:count)', ['count' => count($data['waiting'])]) }}</h2>
                    @foreach ($waitingSections as $key => $rows)
                        @if ($groups !== [] && isset($groupLabel[$key]))
                            <h3 class="mb-1 mt-2 text-xl font-semibold text-sky-200" data-test="tv-group-label">{{ $groupLabel[$key] }}</h3>
                        @endif
                        <ol class="space-y-1">
                            @foreach ($rows as $row)
                                <li class="flex items-baseline justify-between gap-3 text-2xl">
                                    <span class="truncate"><span class="inline-block w-10 tabular-nums text-zinc-400">{{ $row['shown_position'] }}.</span>{{ $row['name'] }}</span>
                                    @if ($row['estimate_minutes'] !== null)
                                        <span class="shrink-0 tabular-nums text-zinc-300">~{{ $row['estimate_minutes'] }} {{ __('min') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endforeach
                    @if ($waitingMore > 0)
                        <p class="mt-1 text-2xl text-zinc-400" data-test="tv-more">{{ __('+:count more', ['count' => $waitingMore]) }}</p>
                    @endif
                    @if ($data['on_break'])
                        <p class="mt-3 text-xl text-zinc-400" data-test="tv-break">
                            <span class="font-semibold text-zinc-300">{{ __('On break:') }}</span>
                            {{ collect($data['on_break'])->pluck('name')->implode(', ') }}
                        </p>
                    @endif
                </section>

                @if ($svg)
                    @include('livewire.public.partials.tv-qr', ['svg' => $svg, 'checkinUrl' => $checkinUrl, 'size' => 'small'])
                @endif
            </aside>
        </div>
    @endif
</div>
