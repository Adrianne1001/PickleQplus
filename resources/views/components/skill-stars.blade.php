@props([
    'playerId',
    'name',
    'stars' => 3,
    // True while the player's skill hasn't been set: empty stars and a muted "Set skill" placeholder.
    'pending' => false,
    // Tappable (a tap calls the host's setSkill action and saves at once) or read-only.
    'editable' => false,
    // The club's StarLevels list, for the level descriptions.
    'levels' => [],
    // Hover descriptions read the `levels` JSON a parent element puts in its Alpine data
    // (x-data="{ levels: ... }"), so a long list doesn't repeat them on every row. Without
    // such a parent the hover text is simply empty (no ReferenceError); the saved level still shows.
    'max' => 6,
])

{{-- Large, evenly spaced skill stars for a board row, set directly: no "Set skill" link, no Save step.
     Mouse hover or keyboard focus previews a level; the saved level's description is always rendered
     on the server, so it stays right after every re-render. Only the hover text is client side. --}}
@php
    $byStars = collect($levels)->keyBy('stars');
    $current = $pending ? 0 : (int) $stars;
    $currentLevel = $byStars->get($current);
@endphp

<div
    x-data="{ hover: 0 }"
    {{ $attributes->class('flex max-w-full flex-col gap-1') }}
    @if ($pending) data-test="skill-pending" @endif
>
    <div
        class="flex max-w-full items-center"
        role="group"
        aria-label="{{ __('Skill for :name', ['name' => $name]) }}"
        x-on:pointerleave="hover = 0"
        data-test="skill-stars"
    >
        @foreach (range(1, $max) as $n)
            @php
                $fill = 'size-9 transition-colors '.($n <= $current ? 'fill-amber-500 stroke-amber-600 dark:fill-amber-400 dark:stroke-amber-300' : 'fill-transparent stroke-zinc-400 dark:stroke-zinc-500');
                $star = '<path d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96L12 2.5z" />';
            @endphp

            @if ($editable)
                <button
                    type="button"
                    wire:key="skill-{{ $playerId }}-{{ $n }}"
                    wire:click="setSkill({{ $playerId }}, {{ $n }})"
                    class="flex size-12 min-w-10 shrink cursor-pointer items-center justify-center rounded-lg focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:outline-hidden dark:focus-visible:ring-brand-400"
                    aria-label="{{ trans_choice(':count star|:count stars', $n) }}{{ $byStars->has($n) ? ', '.__($byStars[$n]['label']) : '' }}"
                    aria-pressed="{{ $n === $current ? 'true' : 'false' }}"
                    x-on:pointerenter="if ($event.pointerType === 'mouse') hover = {{ $n }}"
                    x-on:focus="if ($el.matches(':focus-visible')) hover = {{ $n }}"
                    x-on:blur="hover = 0"
                    data-test="star-{{ $n }}"
                >
                    <svg viewBox="0 0 24 24" class="{{ $fill }}" x-bind:class="hover ? (hover >= {{ $n }} ? 'fill-amber-400! stroke-amber-600!' : 'fill-transparent! stroke-zinc-400!') : ''" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true">{!! $star !!}</svg>
                </button>
            @else
                <span class="flex size-12 min-w-10 shrink items-center justify-center" data-test="star-static-{{ $n }}" @if ($n === $current) aria-current="true" @endif>
                    <svg viewBox="0 0 24 24" class="{{ $fill }}" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true">{!! $star !!}</svg>
                </span>
            @endif
        @endforeach
    </div>

    <div class="min-h-9 max-w-72 text-xs leading-[18px] text-zinc-700 dark:text-zinc-300" data-test="skill-level">
        @if ($editable)
            <p x-cloak x-show="hover">
                <span class="block font-semibold text-zinc-900 dark:text-white" x-text="hover ? '★' + hover + ' · ' + (typeof levels === 'undefined' ? [] : levels)[hover - 1]?.label : ''"></span>
                <span class="block" x-text="hover ? (typeof levels === 'undefined' ? [] : levels)[hover - 1]?.detail : ''"></span>
            </p>
        @endif

        <div @if ($editable) x-show="! hover" @endif>
            @if ($currentLevel)
                <p data-test="skill-level-current">
                    <span class="block font-semibold text-zinc-900 dark:text-white">{{ \App\Support\StarLevelText::title($currentLevel) }}</span>
                    <span class="block">{{ \App\Support\StarLevelText::detail($currentLevel) }}</span>
                </p>
            @elseif ($pending)
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400" data-test="skill-placeholder">{{ $editable ? __('Set skill') : __('Skill not set') }}</p>
            @endif
        </div>
    </div>
</div>
