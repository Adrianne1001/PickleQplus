@props(['podium'])

{{--
    Podium of the first three standings rows: 2nd left, 1st centre (tallest), 3rd right.
    Ties share a rank, a medal and a height. Fewer than three players give a smaller podium.
    Empty list: friendly empty state. Meant to sit inside <x-results.hero> (white text on dark green).
--}}
@php
    $slots = match (count($podium)) {
        0 => [],
        1 => [$podium[0]],
        2 => [$podium[1], $podium[0]],
        default => [$podium[1], $podium[0], $podium[2]],
    };
    $tier = fn (?int $rank): int => min(max($rank ?? 3, 1), 3);
    $look = [
        1 => ['block' => 'h-28 sm:h-36 from-amber-200 to-amber-400 text-amber-950', 'ring' => 'ring-amber-300', 'tint' => 'text-amber-200', 'label' => __('1st place'), 'delay' => 0.55, 'size' => 'size-20 sm:size-24 text-2xl sm:text-3xl'],
        2 => ['block' => 'h-20 sm:h-24 from-zinc-100 to-zinc-300 text-zinc-900', 'ring' => 'ring-zinc-200', 'tint' => 'text-zinc-100', 'label' => __('2nd place'), 'delay' => 0.3, 'size' => 'size-16 sm:size-20 text-xl sm:text-2xl'],
        3 => ['block' => 'h-14 sm:h-[4.5rem] from-orange-200 to-orange-400 text-orange-950', 'ring' => 'ring-orange-300', 'tint' => 'text-orange-200', 'label' => __('3rd place'), 'delay' => 0.05, 'size' => 'size-16 sm:size-20 text-xl sm:text-2xl'],
    ];
@endphp

@if ($slots === [])
    <div class="mx-4 mb-5 rounded-2xl bg-white/10 px-5 py-8 text-center ring-1 ring-white/15 sm:mx-8" data-test="podium-empty">
        <p class="text-4xl" aria-hidden="true">🏓</p>
        <p class="mt-2 text-lg font-bold">{{ __('No finished matches yet') }}</p>
        <p class="mt-1 text-sm text-brand-100">{{ __('The podium appears once matches with scores are done.') }}</p>
    </div>
@else
    <div class="px-3 sm:px-8" data-test="podium" data-count="{{ count($slots) }}">
        <h2 class="sr-only">{{ __('Podium') }}</h2>
        <ol class="mx-auto flex max-w-xl items-end justify-center gap-1.5 sm:gap-3">
            @foreach ($slots as $row)
                @php
                    $t = $tier($row['rank']);
                    $l = $look[$t];
                @endphp
                <li class="flex min-w-0 flex-1 basis-0 flex-col items-center {{ count($slots) < 3 ? 'max-w-48' : '' }}" data-test="podium-spot" data-rank="{{ $row['rank'] }}" data-tier="{{ $t }}">
                    <div class="rs-drop flex w-full flex-col items-center px-0.5 text-center" style="--rs-delay: {{ $l['delay'] + 0.5 }}s">
                        <span class="text-2xl leading-none sm:text-3xl" role="img" aria-label="{{ $l['label'] }}">{{ \App\Support\ResultsShareText::medal($t) }}</span>
                        <span class="{{ $l['size'] }} {{ $l['ring'] }} mt-1.5 flex shrink-0 items-center justify-center rounded-full bg-white font-extrabold text-brand-900 shadow-lg ring-4" aria-hidden="true">{{ $row['initials'] }}</span>
                        <p class="mt-2 w-full text-balance text-sm font-bold leading-tight [overflow-wrap:anywhere] sm:text-lg" data-test="podium-name">{{ $row['name'] }}</p>
                        <p class="mt-0.5 text-xs font-semibold tabular-nums {{ $l['tint'] }} sm:text-sm">{{ $row['wins'] }}–{{ $row['losses'] }}</p>
                        <p class="text-[0.7rem] tabular-nums text-brand-100 sm:text-xs">{{ $row['win_pct'] }}% · {{ $row['point_diff'] > 0 ? '+' : '' }}{{ $row['point_diff'] }}</p>
                    </div>
                    <div class="rs-rise mt-2.5 flex w-full items-start justify-center rounded-t-xl bg-linear-to-b {{ $l['block'] }} pt-2 text-3xl font-black tabular-nums shadow-inner sm:text-5xl" style="--rs-delay: {{ $l['delay'] }}s" aria-hidden="true">{{ $row['rank'] ?? $t }}</div>
                </li>
            @endforeach
        </ol>
    </div>
@endif
