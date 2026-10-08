<div class="space-y-4" wire:poll.10s.visible data-test="results-panel">
    <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Recent results') }}</h2>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="board-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    <div class="grid gap-3 lg:grid-cols-2">
        @forelse ($results as $match)
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900" wire:key="result-{{ $match['id'] }}" data-test="result-row">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white" data-test="result-score">{{ $match['score_a'] }} - {{ $match['score_b'] }}</span>
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ $match['court'] ? __('Court :n', ['n' => $match['court']]).' · ' : '' }}{{ $match['finished_at']?->diffForHumans() }}
                    </span>
                </div>
                <x-match-teams :match="$match" />

                @if ($editingId === $match['id'])
                    <form wire:submit="saveScore" class="mt-4 space-y-3 rounded-xl border-2 border-brand-600/60 bg-brand-50/50 p-4 dark:border-brand-500/60 dark:bg-brand-950/30" data-test="edit-score-form">
                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model="scoreA" type="number" min="0" inputmode="numeric" :label="__('Team A')" class:input="h-14! text-center text-2xl! font-bold tabular-nums" data-test="edit-score-a" />
                            <flux:input wire:model="scoreB" type="number" min="0" inputmode="numeric" :label="__('Team B')" class:input="h-14! text-center text-2xl! font-bold tabular-nums" data-test="edit-score-b" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <flux:button variant="primary" type="submit" class="min-h-12" data-test="save-score-button">{{ __('Save score') }}</flux:button>
                            <flux:button variant="ghost" type="button" class="min-h-12" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                        </div>
                    </form>
                @elseif ($voidingId === $match['id'])
                    <div class="mt-4 space-y-3 rounded-xl border-2 border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/30" data-test="void-result-panel">
                        <flux:text class="text-red-900 dark:text-red-200">{{ __('Void this finished match? It is removed from the players\' game counts and from stats. This cannot be undone.') }}</flux:text>
                        <div class="grid grid-cols-2 gap-2">
                            <flux:button variant="danger" class="min-h-12" wire:click="voidMatch" data-test="confirm-void-result">{{ __('Void match') }}</flux:button>
                            <flux:button variant="ghost" class="min-h-12" wire:click="cancelVoid">{{ __('Cancel') }}</flux:button>
                        </div>
                    </div>
                @else
                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:button class="min-h-12" icon="pencil-square" wire:click="edit({{ $match['id'] }})" data-test="edit-score-button">{{ __('Edit score') }}</flux:button>
                        @if ($match['id'] === $lastId && ! $session->isEnded())
                            <flux:button variant="subtle" class="min-h-12" icon="arrow-uturn-left" wire:click="undoLast" data-test="undo-button">{{ __('Undo last result') }}</flux:button>
                        @endif
                        @unless ($match['exported'])
                            <flux:button variant="ghost" class="min-h-12 text-red-700! dark:text-red-400!" wire:click="confirmVoid({{ $match['id'] }})" data-test="void-result-button">{{ __('Void') }}</flux:button>
                        @endunless
                    </div>
                @endif
            </div>
        @empty
            <div class="lg:col-span-2">
                <x-empty-state icon="trophy" :title="__('No finished matches yet.')" :description="__('Finished matches and their scores show up here, with edit and undo.')" />
            </div>
        @endforelse
    </div>
</div>
