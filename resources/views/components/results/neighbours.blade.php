@props(['previous' => null, 'next' => null, 'allUrl' => null, 'allLabel' => null])

{{-- Previous / next session links. previous and next: ['url', 'name', 'date' (Carbon)] or null. --}}
@php
    $card = 'group flex min-h-14 min-w-0 flex-1 flex-col justify-center rounded-2xl border border-zinc-200 bg-white px-4 py-2.5 shadow-xs hover:border-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-brand-400';
@endphp

<nav class="space-y-2" aria-label="{{ __('Other sessions') }}" data-test="results-neighbours">
    <div class="flex gap-2">
        @if ($previous)
            <a href="{{ $previous['url'] }}" class="{{ $card }}" data-test="previous-session">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">← {{ __('Previous') }}</span>
                <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $previous['name'] }}</span>
                <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $previous['date']->format('j M Y') }}</span>
            </a>
        @endif
        @if ($next)
            <a href="{{ $next['url'] }}" class="{{ $card }} text-end" data-test="next-session">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">{{ __('Next') }} →</span>
                <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $next['name'] }}</span>
                <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $next['date']->format('j M Y') }}</span>
            </a>
        @endif
    </div>
    @if ($allUrl)
        <a href="{{ $allUrl }}" class="inline-flex min-h-10 items-center text-sm font-semibold text-brand-700 hover:underline dark:text-brand-400" data-test="all-sessions">{{ $allLabel ?? __('All sessions') }}</a>
    @endif
</nav>
