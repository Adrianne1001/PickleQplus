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
    // The hover text (the level's hint) reads the `levels` JSON a parent element puts in its Alpine data
    // (x-data="{ levels: [{ hover: '...' }, ...] }"), so a long list doesn't repeat it on every row. Without
    // such a parent the hover text is simply empty (no ReferenceError); the saved level still shows.
    'max' => 6,
])

{{-- Compact skill stars for a board row: small stars with a comfortable tap area, then one short muted
     line ("Intermediate (~3.00–3.49 DUPR)"). A tap on a star saves at once (no link, no Save step). Hovering
     or focusing a star swaps the line for that level's hint in the same spot, so the row never grows.
     The saved level's line is rendered on the server, so it stays right after every re-render. --}}
@php
    $byStars = collect($levels)->keyBy('stars');
    $current = $pending ? 0 : (int) $stars;
    $currentLevel = $byStars->get($current);
    $star = '<path d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96L12 2.5z" />';
@endphp

<div
    x-data="{ hover: 0 }"
    {{ $attributes->class('inline-flex max-w-full flex-wrap items-center gap-x-2') }}
    @if ($pending) data-test="skill-pending" @endif
>
    <div
        class="flex items-center"
        role="group"
        aria-label="{{ __('Skill for :name', ['name' => $name]) }}"
        x-on:pointerleave="hover = 0"
        data-test="skill-stars"
    >
        @foreach (range(1, $max) as $n)
            @php
                $fill = 'size-[18px] transition-colors '.($n <= $current ? 'fill-amber-500 stroke-amber-600 dark:fill-amber-400 dark:stroke-amber-300' : 'fill-transparent stroke-zinc-400 dark:stroke-zinc-500');
            @endphp

            @if ($editable)
                <button
                    type="button"
                    wire:key="skill-{{ $playerId }}-{{ $n }}"
                    wire:click="setSkill({{ $playerId }}, {{ $n }})"
                    class="flex size-7 cursor-pointer items-center justify-center rounded-md focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:outline-hidden dark:focus-visible:ring-brand-400"
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
                <span class="flex size-7 items-center justify-center" data-test="star-static-{{ $n }}" @if ($n === $current) aria-current="true" @endif>
                    <svg viewBox="0 0 24 24" class="{{ $fill }}" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true">{!! $star !!}</svg>
                </span>
            @endif
        @endforeach
    </div>

    <span class="min-w-0 max-w-full truncate text-xs text-zinc-600 dark:text-zinc-400" data-test="skill-level">
        @if ($editable)
            <span x-cloak x-show="hover" x-text="hover ? (typeof levels === 'undefined' ? [] : levels)[hover - 1]?.hover : ''" data-test="skill-level-hover"></span>
        @endif

        <span @if ($editable) x-show="! hover" @endif>
            @if ($currentLevel)
                <span data-test="skill-level-current">{{ \App\Support\StarLevelText::short($currentLevel) }}</span>
            @elseif ($pending)
                <span class="font-medium" data-test="skill-placeholder">{{ $editable ? __('Set skill') : __('Skill not set') }}</span>
            @endif
        </span>
    </span>
</div>
