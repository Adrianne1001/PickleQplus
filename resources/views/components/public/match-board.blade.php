@props(['teams', 'title' => null, 'group' => null, 'elapsed' => null, 'open' => false, 'compact' => false])
{{-- Side-by-side match board: Team A (brand) vs Team B (sky). The open state keeps the same shape. --}}
<div {{ $attributes->class(['overflow-hidden rounded-2xl border', $open ? 'border-dashed border-zinc-300 bg-transparent dark:border-zinc-700' : 'border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900']) }} data-test="match-board">
    <div class="flex items-center justify-between gap-2 px-3 {{ $compact ? 'py-1.5' : 'py-2' }} {{ $open ? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' : 'bg-zinc-900 text-white dark:bg-zinc-800' }}">
        <span class="flex min-w-0 items-center gap-2 text-xs font-bold uppercase tracking-wider">
            <span class="truncate">{{ $title }}</span>
            @if ($group)
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[0.65rem] font-semibold normal-case tracking-normal text-amber-900 dark:bg-amber-900 dark:text-amber-100" data-test="queue-court-group">{{ $group }}</span>
            @endif
        </span>
        @if ($elapsed !== null)
            <span class="inline-flex shrink-0 items-center gap-1 text-xs tabular-nums" data-test="match-elapsed">
                <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="M10 5.5V10l3 2" stroke-linecap="round"/></svg>
                {{ $elapsed }} {{ __('min') }}
            </span>
        @endif
    </div>

    @if ($open)
        <p class="px-4 {{ $compact ? 'py-3' : 'py-6' }} text-center text-zinc-600 dark:text-zinc-400" data-test="court-open">{{ __('Open') }}</p>
    @else
        <div class="relative grid grid-cols-2 gap-2 p-2">
            @foreach (['A' => 'brand', 'B' => 'sky'] as $side => $tone)
                <div class="min-w-0 rounded-xl border-s-4 {{ $side === 'A' ? 'ps-3 pe-6' : 'ps-6 pe-3' }} {{ $compact ? 'py-2' : 'py-3' }} {{ $tone === 'brand' ? 'border-brand-600 bg-brand-50 text-zinc-900 dark:border-brand-500 dark:bg-brand-950 dark:text-brand-50' : 'border-sky-600 bg-sky-50 text-zinc-900 dark:border-sky-500 dark:bg-sky-950 dark:text-sky-50' }}" data-test="team-{{ strtolower($side) }}">
                    <p class="mb-1 text-[0.65rem] font-bold uppercase tracking-wider {{ $tone === 'brand' ? 'text-brand-800 dark:text-brand-300' : 'text-sky-800 dark:text-sky-300' }}">{{ __('Team :t', ['t' => $side]) }}</p>
                    <ul class="space-y-1">
                        @foreach ($teams[$side] as $p)
                            <li class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 {{ $compact ? 'text-sm' : 'text-base' }} font-medium leading-tight [overflow-wrap:anywhere]" data-test="match-player">
                                <span class="min-w-0 [overflow-wrap:anywhere]"><x-public.player :id="$p['id']" :name="$p['name']" /></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            <span class="pointer-events-none absolute left-1/2 top-1/2 flex size-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-zinc-900 text-[0.65rem] font-extrabold text-white ring-2 ring-white dark:bg-white dark:text-zinc-900 dark:ring-zinc-900" data-test="match-vs">{{ __('VS') }}</span>
        </div>
    @endif
</div>
