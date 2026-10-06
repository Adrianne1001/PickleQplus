{{-- Inline swap / remove / void panel for one match. Needs $match, $candidates and the ManagesMatchPlayers state. --}}
@if ($panel !== null && $panelMatchId === $match['id'] && in_array($panel, ['swap', 'remove', 'void'], true))
    <div class="mt-3 space-y-3 rounded-lg border border-zinc-300 p-3 dark:border-zinc-600" data-test="match-panel-{{ $panel }}">
        @if ($panel === 'swap' || $panel === 'remove')
            <flux:select wire:model="outPlayerId" :label="$panel === 'swap' ? __('Swap out') : __('Remove')" data-test="out-player">
                <flux:select.option value="">{{ __('Choose a player') }}</flux:select.option>
                @foreach (['A', 'B'] as $team)
                    @foreach ($match['teams'][$team] as $player)
                        <flux:select.option value="{{ $player['id'] }}">{{ $player['name'] }}</flux:select.option>
                    @endforeach
                @endforeach
            </flux:select>
        @endif

        @if ($panel === 'swap')
            <flux:select wire:model="inPlayerId" :label="__('Swap in (waiting)')" data-test="in-player">
                <flux:select.option value="">{{ __('Choose a waiting player') }}</flux:select.option>
                @foreach ($candidates as $candidate)
                    <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button variant="primary" class="w-full" wire:click="swap" data-test="confirm-swap">{{ __('Swap') }}</flux:button>
        @elseif ($panel === 'remove')
            <flux:select wire:model="removeStatus" :label="__('Removed player becomes')" data-test="remove-status">
                <flux:select.option value="waiting">{{ __('Waiting') }}</flux:select.option>
                <flux:select.option value="break">{{ __('On break') }}</flux:select.option>
                <flux:select.option value="left">{{ __('Left the session') }}</flux:select.option>
            </flux:select>
            <flux:text class="text-sm">{{ __('The best waiting player fills the slot.') }}</flux:text>
            <flux:button variant="primary" class="w-full" wire:click="remove" data-test="confirm-remove">{{ __('Remove player') }}</flux:button>
        @else
            <flux:text>{{ $match['status'] === 'playing' ? __('Void this match? Nobody gets a game counted and the court is freed.') : __('Void this Up Next match? The players go back to the waiting list.') }}</flux:text>
            <flux:button variant="danger" class="w-full" wire:click="voidMatch" data-test="confirm-void">{{ __('Void match') }}</flux:button>
        @endif

        <flux:button variant="ghost" class="w-full" wire:click="closePanel">{{ __('Cancel') }}</flux:button>
    </div>
@endif
