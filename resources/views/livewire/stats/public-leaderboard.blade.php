<main class="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-6 px-4 pb-24 pt-5" data-test="public-stats-page">
    <header class="space-y-2">
        <x-brand size="sm" />
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-400">{{ __('Leaderboard') }}</p>
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $clubName }}</h1>
    </header>

    <div class="max-w-xs">
        <label for="period" class="mb-1 block text-sm font-semibold">{{ __('Period') }}</label>
        <select id="period" wire:model.live="period" class="min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 py-3 text-base text-zinc-900 focus:outline-2 focus:outline-brand-600 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" data-test="period-select">
            @foreach ($periods as $p)
                <option value="{{ $p->value }}">{{ __($p->label()) }}</option>
            @endforeach
        </select>
    </div>

    <x-stats.table :rows="$board['ranked']" :empty="__('Nobody is ranked yet for this period.')" data-test="ranked-table" />

    @if ($board['unranked'])
        <section class="space-y-3" data-test="unranked-section">
            <div>
                <h2 class="text-lg font-bold">{{ __('Not ranked yet') }}</h2>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Fewer than :count games in this period.', ['count' => $board['min_games']]) }}</p>
            </div>
            <x-stats.table :rows="$board['unranked']" :show-rank="false" />
        </section>
    @endif
</main>
