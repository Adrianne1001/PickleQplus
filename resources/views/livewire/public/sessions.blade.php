<main class="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-5 px-4 pb-24 pt-5" data-test="public-sessions-page">
    <x-public.club-header :club-name="$club->name" :slug="$club->slug" active="sessions" />

    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Sessions') }}</h1>

    @if ($list['live'])
        <a href="{{ route('public.queue', [$club->slug, $list['live']['public_id']]) }}" class="flex min-h-16 items-center gap-3 rounded-2xl bg-brand-700 px-4 py-3 text-white shadow-md ring-4 ring-brand-300/60 hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:ring-brand-500/30" data-test="live-banner">
            <span class="size-3 shrink-0 animate-pulse rounded-full bg-ball" aria-hidden="true"></span>
            <span class="min-w-0 flex-1">
                <span class="block text-xs font-bold uppercase tracking-wider text-brand-100">{{ __('Live now') }}</span>
                <span class="block truncate text-lg font-extrabold">{{ $list['live']['name'] }}</span>
            </span>
            <span class="shrink-0 text-sm font-semibold">{{ __('Open queue') }} →</span>
        </a>
    @endif

    @if ($list['sessions'] === [])
        <x-empty-state icon="calendar-days" :title="__('No finished sessions yet')" :description="__('Results appear here after each session ends.')" data-test="sessions-empty" />
    @else
        <ul class="space-y-3" data-test="sessions-list">
            @foreach ($list['sessions'] as $s)
                <li wire:key="session-{{ $s['public_id'] }}">
                    <a href="{{ route('public.queue', [$club->slug, $s['public_id']]) }}" class="block rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs hover:border-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-brand-400" data-test="session-card">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-base font-bold text-zinc-900 dark:text-white">{{ $s['name'] }}</p>
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $s['date']->format('D, j M Y') }}</p>
                            </div>
                            <span class="shrink-0 text-lg text-zinc-600 dark:text-zinc-400" aria-hidden="true">→</span>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ trans_choice(':count match|:count matches', $s['matches']) }}</span>
                            <span class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ trans_choice(':count player|:count players', $s['players']) }}</span>
                            @if ($s['top_player'])
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-200" data-test="top-player"><span aria-hidden="true">🏆</span>{{ $s['top_player'] }}</span>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($list['last_page'] > 1)
            <nav class="flex items-center justify-between gap-3" aria-label="{{ __('Pages') }}" data-test="sessions-pagination">
                @if ($list['page'] > 1)
                    <a href="{{ route('public.sessions', $club->slug) }}{{ $list['page'] > 2 ? '?page='.($list['page'] - 1) : '' }}" class="inline-flex min-h-11 items-center rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-900 dark:hover:bg-zinc-800" rel="prev" data-test="page-prev">← {{ __('Newer') }}</a>
                @else
                    <span></span>
                @endif
                <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-400">{{ __('Page :page of :last', ['page' => $list['page'], 'last' => $list['last_page']]) }}</span>
                @if ($list['page'] < $list['last_page'])
                    <a href="{{ route('public.sessions', $club->slug) }}?page={{ $list['page'] + 1 }}" class="inline-flex min-h-11 items-center rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-900 dark:hover:bg-zinc-800" rel="next" data-test="page-next">{{ __('Older') }} →</a>
                @else
                    <span></span>
                @endif
            </nav>
        @endif
    @endif
</main>
