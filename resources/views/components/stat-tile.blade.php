{{--
    Stat tile: a big number with a label.
    Props: label (required), value (required), hint (small line under the value), icon (optional),
           tone ('default'|'brand'|'amber'|'red'; colours the icon chip only).
--}}
@props([
    'label',
    'value',
    'hint' => null,
    'icon' => null,
    'tone' => 'default',
])

@php
    $chip = [
        'default' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
        'brand' => 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-400',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
        'red' => 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400',
    ][$tone] ?? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
@endphp

<div {{ $attributes->class(['rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs sm:p-5 dark:border-zinc-700 dark:bg-zinc-900']) }} data-ui="stat-tile">
    <div class="flex items-center justify-between gap-3">
        <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ $label }}</p>
        @if ($icon)
            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $chip }}">
                <flux:icon :icon="$icon" class="size-4" />
            </span>
        @endif
    </div>
    <p class="mt-2 text-3xl font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</p>
    @endif
</div>
