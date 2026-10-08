@props(['clubName', 'slug', 'active' => 'sessions'])

{{-- Public club header: brand, club name and the Sessions | Leaderboard tabs. Props: clubName, slug, active (sessions|leaderboard). --}}
@php
    $tabs = [
        'sessions' => [__('Sessions'), route('public.sessions', $slug)],
        'leaderboard' => [__('Leaderboard'), route('public.stats', $slug)],
    ];
@endphp

<header {{ $attributes->class(['space-y-3']) }} data-test="public-club-header">
    <div class="flex items-center justify-between gap-3">
        <x-brand size="sm" />
        <a href="{{ route('public.sessions', $slug) }}" class="min-w-0 truncate text-sm font-bold text-zinc-900 hover:underline dark:text-white" data-test="club-name">{{ $clubName }}</a>
    </div>
    <nav class="flex gap-1 rounded-2xl bg-zinc-100 p-1 dark:bg-zinc-800" aria-label="{{ $clubName }}">
        @foreach ($tabs as $key => [$label, $url])
            <a href="{{ $url }}" data-test="tab-{{ $key }}" @if ($active === $key) aria-current="page" @endif
                @class([
                    'flex min-h-11 flex-1 items-center justify-center rounded-xl px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                    'bg-white text-brand-800 shadow-xs dark:bg-zinc-950 dark:text-brand-300' => $active === $key,
                    'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $active !== $key,
                ])>{{ $label }}</a>
        @endforeach
    </nav>
</header>
