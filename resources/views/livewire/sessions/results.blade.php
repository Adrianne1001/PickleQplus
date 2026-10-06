<div class="space-y-4" wire:poll.10s.visible data-test="results-panel">
    <flux:heading size="lg">{{ __('Recent results') }}</flux:heading>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="board-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    <div class="space-y-3">
        @forelse ($results as $match)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="result-{{ $match['id'] }}" data-test="result-row">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xl font-semibold tabular-nums" data-test="result-score">{{ $match['score_a'] }} - {{ $match['score_b'] }}</span>
                    <span class="text-sm text-zinc-500">
                        {{ $match['court'] ? __('Court :n', ['n' => $match['court']]).' · ' : '' }}{{ $match['finished_at']?->diffForHumans() }}
                    </span>
                </div>
                <x-match-teams :match="$match" />

                @if ($editingId === $match['id'])
                    <form wire:submit="saveScore" class="mt-3 space-y-3 rounded-lg border border-zinc-300 p-3 dark:border-zinc-600" data-test="edit-score-form">
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input wire:model="scoreA" type="number" min="0" inputmode="numeric" :label="__('Team A')" data-test="edit-score-a" />
                            <flux:input wire:model="scoreB" type="number" min="0" inputmode="numeric" :label="__('Team B')" data-test="edit-score-b" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <flux:button variant="primary" type="submit" class="min-h-12" data-test="save-score-button">{{ __('Save score') }}</flux:button>
                            <flux:button variant="ghost" type="button" class="min-h-12" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                        </div>
                    </form>
                @elseif ($voidingId === $match['id'])
                    <div class="mt-3 space-y-3 rounded-lg border border-zinc-300 p-3 dark:border-zinc-600" data-test="void-result-panel">
                        <flux:text>{{ __('Void this finished match? It is removed from the players\' game counts and from stats. This cannot be undone.') }}</flux:text>
                        <div class="grid grid-cols-2 gap-2">
                            <flux:button variant="danger" class="min-h-12" wire:click="voidMatch" data-test="confirm-void-result">{{ __('Void match') }}</flux:button>
                            <flux:button variant="ghost" class="min-h-12" wire:click="cancelVoid">{{ __('Cancel') }}</flux:button>
                        </div>
                    </div>
                @else
                    <div class="mt-3 flex flex-wrap gap-2">
                        <flux:button class="min-h-12" wire:click="edit({{ $match['id'] }})" data-test="edit-score-button">{{ __('Edit score') }}</flux:button>
                        @if ($match['id'] === $lastId && ! $session->isEnded())
                            <flux:button variant="subtle" class="min-h-12" wire:click="undoLast" data-test="undo-button">{{ __('Undo last result') }}</flux:button>
                        @endif
                        @unless ($match['exported'])
                            <flux:button variant="subtle" class="min-h-12" wire:click="confirmVoid({{ $match['id'] }})" data-test="void-result-button">{{ __('Void') }}</flux:button>
                        @endunless
                    </div>
                @endif
            </div>
        @empty
            <flux:text>{{ __('No finished matches yet.') }}</flux:text>
        @endforelse
    </div>
</div>
