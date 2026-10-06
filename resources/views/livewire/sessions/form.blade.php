@php
    $ended = $session?->isEnded() ?? false;
@endphp

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ $session ? __('Edit session') : __('New session') }}</flux:heading>
        <flux:subheading>{{ $club->name }}</flux:subheading>
    </div>

    @if ($ended)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="ended-callout">
            <flux:callout.text>{{ __('This session has ended and can no longer be edited.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="space-y-6" data-test="session-form">
        <flux:error name="status" />

        <flux:input wire:model="name" :label="__('Name')" required maxlength="120" :disabled="$ended" />

        <flux:input wire:model="date" type="date" :label="__('Date')" required :disabled="$ended" />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="courts" type="number" min="1" max="50" inputmode="numeric" :label="__('Courts')" required :disabled="$ended" />
            <flux:input wire:model="up_next_count" type="number" min="1" max="3" inputmode="numeric" :label="__('Up Next slots')" required :disabled="$ended" />
        </div>

        <flux:field variant="inline">
            <flux:checkbox wire:model="auto_fill" :disabled="$ended" data-test="auto-fill" />
            <flux:label>{{ __('Auto-fill courts') }}</flux:label>
            <flux:description>{{ __('Start the next Up Next match automatically when a court frees up.') }}</flux:description>
        </flux:field>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="to" :label="__('Game to')" :disabled="$ended">
                @foreach ([11, 15, 21] as $points)
                    <flux:select.option value="{{ $points }}">{{ $points }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="win_by" :label="__('Win by')" :disabled="$ended">
                @foreach ([1, 2] as $margin)
                    <flux:select.option value="{{ $margin }}">{{ $margin }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex flex-wrap gap-3">
            @unless ($ended)
                <flux:button variant="primary" type="submit" data-test="save-session-button">{{ __('Save session') }}</flux:button>
            @endunless
            <flux:button
                variant="ghost"
                :href="$session ? route('clubs.sessions.show', [$club, $session]) : route('clubs.sessions.index', $club)"
                wire:navigate
            >{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
