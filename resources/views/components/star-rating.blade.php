@props([
    'stars' => null,
])

@php
    $count = $stars === null ? 0 : max(0, min(6, (int) $stars));
@endphp

<span
    {{ $attributes->class('inline-flex items-center whitespace-nowrap font-medium') }}
    role="img"
    aria-label="{{ $stars === null ? __('No stars') : trans_choice(':count star|:count stars', $count) }}"
>
    <span class="text-amber-600 dark:text-amber-400" aria-hidden="true">{{ str_repeat('★', $count) }}</span><span class="text-zinc-300 dark:text-zinc-700" aria-hidden="true">{{ str_repeat('★', 6 - $count) }}</span>
</span>
