<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Leaderboard') }}</flux:heading>
            <flux:subheading>{{ $club->name }}</flux:subheading>
        </div>
        <flux:select wire:model.live="period" :label="__('Period')" class="w-48" data-test="period-select">
            @foreach ($periods as $p)
                <flux:select.option value="{{ $p->value }}">{{ __($p->label()) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <x-stats.table :rows="$ranked" :empty="__('Nobody is ranked yet for this period.')" data-test="ranked-table" />

    @if ($unranked)
        <div class="space-y-3" data-test="unranked-section">
            <div>
                <flux:heading size="lg">{{ __('Not ranked yet') }}</flux:heading>
                <flux:subheading>{{ __('Fewer than :count games in this period.', ['count' => $minGames]) }}</flux:subheading>
            </div>
            <x-stats.table :rows="$unranked" :show-rank="false" />
        </div>
    @endif
</section>
