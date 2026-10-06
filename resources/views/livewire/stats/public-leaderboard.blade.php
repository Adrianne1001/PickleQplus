<main class="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-5 px-4 py-5" data-test="public-stats-page">
    <header>
        <h1 class="text-2xl font-bold">{{ $clubName }}</h1>
        <p class="text-zinc-600 dark:text-zinc-300">{{ __('Leaderboard') }}</p>
    </header>

    <div>
        <label for="period" class="mb-1 block text-sm font-medium">{{ __('Period') }}</label>
        <select id="period" wire:model.live="period" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-3 text-base dark:border-zinc-600 dark:bg-zinc-900" data-test="period-select">
            @foreach ($periods as $p)
                <option value="{{ $p->value }}">{{ __($p->label()) }}</option>
            @endforeach
        </select>
    </div>

    <x-stats.table :rows="$board['ranked']" :empty="__('Nobody is ranked yet for this period.')" data-test="ranked-table" />

    @if ($board['unranked'])
        <section class="space-y-2" data-test="unranked-section">
            <div>
                <h2 class="text-xl font-bold">{{ __('Not ranked yet') }}</h2>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Fewer than :count games in this period.', ['count' => $board['min_games']]) }}</p>
            </div>
            <x-stats.table :rows="$board['unranked']" :show-rank="false" />
        </section>
    @endif
</main>
