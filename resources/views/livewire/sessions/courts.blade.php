<div class="space-y-4" wire:poll.10s.visible data-test="courts-panel">
    <flux:heading size="lg">{{ __('Courts') }}</flux:heading>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="board-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($courts as $row)
            @php($match = $row['match'])
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="court-{{ $row['court'] }}" data-test="court" data-court="{{ $row['court'] }}">
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-lg font-semibold">{{ __('Court :n', ['n' => $row['court']]) }}</span>
                    @if ($match)
                        <flux:badge color="green" size="sm">{{ __(':min min', ['min' => $match['elapsed_minutes']]) }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">{{ __('Free') }}</flux:badge>
                    @endif
                </div>

                @if ($match)
                    <x-match-teams :match="$match" />

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <flux:button variant="primary" class="min-h-12" wire:click="openPanel('score', {{ $match['id'] }})" data-test="score-button">{{ __('Enter score') }}</flux:button>
                        <flux:button class="min-h-12" wire:click="openPanel('swap', {{ $match['id'] }})" data-test="swap-button">{{ __('Swap') }}</flux:button>
                        <flux:button class="min-h-12" wire:click="openPanel('remove', {{ $match['id'] }})" data-test="remove-button">{{ __('Remove') }}</flux:button>
                        <flux:button variant="subtle" class="min-h-12" wire:click="openPanel('void', {{ $match['id'] }})" data-test="void-button">{{ __('Void') }}</flux:button>
                    </div>

                    @if ($panel === 'score' && $panelMatchId === $match['id'])
                        <form wire:submit="finish" class="mt-3 space-y-3 rounded-lg border border-zinc-300 p-3 dark:border-zinc-600" data-test="score-form">
                            <div class="grid grid-cols-2 gap-3">
                                <flux:input wire:model.live.debounce.300ms="scoreA" type="number" min="0" inputmode="numeric" :label="__('Team A')" class="text-2xl" data-test="score-a" />
                                <flux:input wire:model.live.debounce.300ms="scoreB" type="number" min="0" inputmode="numeric" :label="__('Team B')" class="text-2xl" data-test="score-b" />
                            </div>
                            @if ($this->scoreHint)
                                <flux:text class="text-sm text-amber-600 dark:text-amber-400" data-test="score-hint">{{ $this->scoreHint }}</flux:text>
                            @endif
                            <div class="grid grid-cols-2 gap-2">
                                <flux:button variant="primary" type="submit" class="min-h-12" data-test="finish-button">{{ __('Finish match') }}</flux:button>
                                <flux:button variant="ghost" type="button" class="min-h-12" wire:click="closePanel">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @endif

                    @include('livewire.sessions.partials.match-panel', ['match' => $match, 'candidates' => $this->candidates])
                @else
                    <flux:text>{{ __('Nobody is playing on this court.') }}</flux:text>
                @endif
            </div>
        @endforeach
    </div>
</div>
