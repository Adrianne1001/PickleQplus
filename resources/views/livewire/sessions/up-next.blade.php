<div class="space-y-4" wire:poll.10s.visible data-test="up-next-panel">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <flux:heading size="lg">{{ __('Up Next') }}</flux:heading>

        <div class="flex flex-wrap items-end gap-4">
            <flux:field variant="inline">
                <flux:switch wire:model.live="autoFill" data-test="auto-fill-toggle" />
                <flux:label>{{ __('Auto-fill courts') }}</flux:label>
            </flux:field>
            <flux:select wire:model.live="upNextCount" :label="__('Up Next slots')" class="w-24" data-test="up-next-count">
                @foreach ([1, 2, 3] as $n)
                    <flux:select.option value="{{ $n }}">{{ $n }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="board-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    @if ($freeCourts !== [] && $staged !== [])
        <flux:select wire:model="courtChoice" :label="__('Start on court')" data-test="court-choice">
            <flux:select.option value="">{{ __('Lowest free court (:n)', ['n' => $freeCourts[0]]) }}</flux:select.option>
            @foreach ($freeCourts as $court)
                <flux:select.option value="{{ $court }}">{{ __('Court :n', ['n' => $court]) }}</flux:select.option>
            @endforeach
        </flux:select>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($staged as $match)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="staged-{{ $match['id'] }}" data-test="staged-match">
                <x-match-teams :match="$match" />

                <div class="mt-3 grid grid-cols-2 gap-2">
                    <flux:button variant="primary" class="min-h-12" wire:click="start({{ $match['id'] }})" :disabled="$freeCourts === []" data-test="start-match-button">
                        {{ $freeCourts === [] ? __('No free court') : __('Start') }}
                    </flux:button>
                    <flux:button class="min-h-12" wire:click="reroll({{ $match['id'] }})" data-test="reroll-button">{{ __('Re-roll') }}</flux:button>
                    <flux:button class="min-h-12" wire:click="openPanel('swap', {{ $match['id'] }})" data-test="swap-button">{{ __('Swap') }}</flux:button>
                    <flux:button class="min-h-12" wire:click="openPanel('remove', {{ $match['id'] }})" data-test="remove-button">{{ __('Remove') }}</flux:button>
                    <flux:button variant="subtle" class="col-span-2 min-h-12" wire:click="openPanel('void', {{ $match['id'] }})" data-test="void-button">{{ __('Void') }}</flux:button>
                </div>

                @include('livewire.sessions.partials.match-panel', ['match' => $match, 'candidates' => $this->candidates])
            </div>
        @empty
            <flux:text data-test="up-next-empty">{{ __('Nothing staged. Up Next fills when four players are waiting.') }}</flux:text>
        @endforelse
    </div>
</div>
