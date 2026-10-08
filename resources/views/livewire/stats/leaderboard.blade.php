<section class="w-full max-w-4xl space-y-6">
    <x-page-header :eyebrow="$club->name" :title="__('Leaderboard')" :description="__('Standings across your finished matches. Change the period to see a different window.')">
        <x-slot:actions>
            <flux:select wire:model.live="period" :label="__('Period')" class="w-48" data-test="period-select">
                @foreach ($periods as $p)
                    <flux:select.option value="{{ $p->value }}">{{ __($p->label()) }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-page-header>

    <x-stats.table :rows="$ranked" :empty="__('Nobody is ranked yet for this period.')" data-test="ranked-table" />

    @if ($unranked)
        <div class="space-y-3" data-test="unranked-section">
            <div>
                <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Not ranked yet') }}</h2>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Fewer than :count games in this period.', ['count' => $minGames]) }}</p>
            </div>
            <x-stats.table :rows="$unranked" :show-rank="false" />
        </div>
    @endif
</section>
