@props([
    'open' => false,
    'term' => '',
    'deactivated' => null,
    'show' => false,
    'action' => 'saveQuick',
    'submitLabel' => null,
    'roster' => null,
])

{{-- Quick-add row and inline form. Bound to the host's $quick array and openQuickAdd/closeQuickAdd. --}}
@if ($deactivated)
    <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-200" data-test="deactivated-note">
        {{ __(':name is deactivated. Reactivate them on the Players page.', ['name' => $deactivated->name]) }}
        @if ($roster)
            <a href="{{ $roster }}" wire:navigate class="font-semibold underline underline-offset-2">{{ __('Open Players') }}</a>
        @endif
    </div>
@elseif ($open)
    <div x-on:keydown.enter="if ($event.target.tagName === 'INPUT') { $event.preventDefault(); $wire.{{ $action }}() } else if ($event.target.tagName !== 'BUTTON') { $event.preventDefault() }" class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50" data-test="quick-add-form">
        <flux:heading>{{ __('New player') }}</flux:heading>

        <flux:input wire:model="quick.name" :label="__('Name')" required maxlength="120" autocomplete="off" data-test="quick-name" />

        <flux:field data-test="quick-stars">
            <flux:label>{{ __('Stars (optional)') }}</flux:label>
            <x-star-picker model="quick.stars" :label="__('Stars')" />
            <flux:description>
                {{ __('Leave blank to set the skill later. The player starts at 3 stars until you do.') }}
                <button type="button" x-cloak x-show="$wire.quick.stars" x-on:click="$wire.quick.stars = ''" class="ms-1 font-semibold text-brand-700 underline underline-offset-2 dark:text-brand-400" data-test="quick-stars-clear">{{ __('Clear stars') }}</button>
            </flux:description>
            <flux:error name="quick.stars" />
        </flux:field>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="quick.dupr_id" :label="__('DUPR ID (optional)')" maxlength="6" autocomplete="off" class="uppercase" placeholder="8DPLX8" data-test="quick-dupr" />
            <flux:select wire:model="quick.gender" :label="__('Gender (optional)')" data-test="quick-gender">
                <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                <flux:select.option value="man">{{ __('Man') }}</flux:select.option>
                <flux:select.option value="woman">{{ __('Woman') }}</flux:select.option>
            </flux:select>
        </div>

        <div class="flex flex-wrap gap-3">
            <flux:button type="button" variant="primary" class="min-h-11" wire:click="{{ $action }}" data-test="quick-save">{{ $submitLabel ?? __('Add to list') }}</flux:button>
            <flux:button type="button" variant="ghost" class="min-h-11" wire:click="closeQuickAdd" data-test="quick-cancel">{{ __('Cancel') }}</flux:button>
        </div>
    </div>
@elseif ($show)
    <button
        type="button"
        wire:click="openQuickAdd"
        class="flex min-h-11 w-full items-center gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-2 text-left text-sm font-medium text-brand-700 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-brand-600 dark:border-zinc-600 dark:text-brand-400 dark:hover:bg-zinc-800"
        data-test="quick-add-row"
    >
        <flux:icon.plus class="size-4 shrink-0" />
        <span class="min-w-0 break-words">{{ __('Add “:term” as a new player', ['term' => $term]) }}</span>
    </button>
@endif
