@props([
    'model',
    'label' => null,
    'max' => 6,
])

{{-- Clickable 1..max star picker. Real (visually hidden) radio inputs give arrow-key and Tab support;
     state is entangled with the Livewire property named by $model (deferred, like wire:model). --}}
@php($uid = 'star-picker-'.\Illuminate\Support\Str::slug($model))

<div
    x-data="{ value: $wire.entangle('{{ $model }}'), hover: 0 }"
    {{ $attributes->class('space-y-1') }}
    role="radiogroup"
    aria-label="{{ $label ?? __('Stars') }}"
>
    <div class="flex items-center gap-3">
        <div class="flex items-center" x-on:mouseleave="hover = 0">
            @foreach (range(1, $max) as $n)
                <label
                    class="relative flex size-11 cursor-pointer items-center justify-center rounded-lg has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-600 has-[:focus-visible]:ring-offset-2 dark:has-[:focus-visible]:ring-brand-400 dark:has-[:focus-visible]:ring-offset-zinc-900"
                    x-on:mouseenter="hover = {{ $n }}"
                >
                    <input
                        type="radio"
                        name="{{ $uid }}"
                        value="{{ $n }}"
                        class="peer sr-only"
                        aria-label="{{ trans_choice(':count star|:count stars', $n) }}"
                        x-bind:checked="Number(value) === {{ $n }}"
                        x-on:change="value = '{{ $n }}'"
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
    </div>
</div>
