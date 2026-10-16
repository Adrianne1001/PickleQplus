@props([
    'model',
    'label' => null,
    'max' => 6,
    // The club's StarLevels list. When given, the picker describes the hovered or selected level.
    'levels' => null,
    // Show a "Clear" button once a value is picked, which sets the model to $empty.
    'clearable' => false,
    'empty' => '',
])

{{-- Clickable 1..max star picker. Real (visually hidden) radio inputs give arrow-key and Tab support;
     state is entangled with the Livewire property named by $model (deferred, like wire:model).
     Hovering (mouse), focusing (keyboard) or selecting a star shows what that level means. The
     description line has a reserved height so the layout doesn't jump. --}}
@php($uid = 'star-picker-'.\Illuminate\Support\Str::slug($model))
@php($byStars = collect($levels ?? [])->keyBy('stars'))

<div
    x-data="{ value: $wire.entangle('{{ $model }}'), hover: 0 }"
    {{ $attributes->class('space-y-2') }}
    role="radiogroup"
    aria-label="{{ $label ?? __('Stars') }}"
>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <div class="flex max-w-full items-center" x-on:pointerleave="hover = 0">
            @foreach (range(1, $max) as $n)
                <label
                    class="relative flex size-11 min-w-9 shrink cursor-pointer items-center justify-center rounded-lg has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-600 has-[:focus-visible]:ring-offset-2 dark:has-[:focus-visible]:ring-brand-400 dark:has-[:focus-visible]:ring-offset-zinc-900"
                    x-on:pointerenter="if ($event.pointerType === 'mouse') hover = {{ $n }}"
                >
                    <input
                        type="radio"
                        name="{{ $uid }}"
                        value="{{ $n }}"
                        class="peer sr-only"
                        aria-label="{{ trans_choice(':count star|:count stars', $n) }}"
                        @if ($byStars->isNotEmpty()) aria-describedby="{{ $uid }}-levels" @endif
                        x-bind:checked="Number(value) === {{ $n }}"
                        x-on:change="value = '{{ $n }}'"
                        x-on:focus="if ($el.matches(':focus-visible')) hover = {{ $n }}"
                        x-on:blur="hover = 0"
                        data-test="star-{{ $n }}"
                    />
                    <svg
                        viewBox="0 0 24 24"
                        class="size-8 transition-colors"
                        x-bind:class="(hover || Number(value)) >= {{ $n }} ? 'fill-amber-500 stroke-amber-600 dark:fill-amber-400 dark:stroke-amber-300' : 'fill-transparent stroke-zinc-400 dark:stroke-zinc-500'"
                        stroke-width="1.5"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96L12 2.5z" />
                    </svg>
                </label>
            @endforeach
        </div>

        <span class="text-sm font-medium tabular-nums text-zinc-700 dark:text-zinc-300" data-test="stars-label" aria-live="polite">
            <template x-if="Number(value) >= 1"><span x-text="Number(value) + ' {{ __('of') }} {{ $max }}'"></span></template>
            <template x-if="! (Number(value) >= 1)"><span>{{ __('Not set') }}</span></template>
        </span>

        @if ($clearable)
            <button
                type="button"
                x-cloak
                x-show="Number(value) >= 1"
                x-on:click="value = @js($empty); hover = 0"
                class="min-h-11 rounded-lg px-2 text-sm font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-brand-600 dark:text-brand-400"
                data-test="stars-clear"
            >{{ __('Clear') }}</button>
        @endif
    </div>

    @if ($byStars->isNotEmpty())
        <div id="{{ $uid }}-levels" class="min-h-14 text-sm leading-5 text-zinc-700 dark:text-zinc-300" data-test="star-levels">
            @foreach (range(1, $max) as $n)
                @if ($byStars->has($n))
                    <p x-cloak x-show="(hover || Number(value)) === {{ $n }}" data-test="star-level-{{ $n }}">
                        <span class="block font-semibold text-zinc-900 dark:text-white">{{ \App\Support\StarLevelText::title($byStars[$n]) }}</span>
                        <span class="block">{{ \App\Support\StarLevelText::detail($byStars[$n]) }}</span>
                    </p>
                @endif
            @endforeach
            <p x-show="! (hover || Number(value))" class="text-zinc-600 dark:text-zinc-400" data-test="star-levels-hint">{{ __('Pick a star to see what that level means.') }}</p>
        </div>
    @endif
</div>
