{{-- Inline "Set skill" star picker for one player. Uses the panel's skillFor / skillStars state. --}}
<div class="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50" data-test="skill-picker">
    <x-star-picker model="skillStars" :label="__('Skill for :name', ['name' => $name])" />
    @error('stars')
        <p class="text-sm font-medium text-red-700 dark:text-red-400" data-test="skill-error">{{ $message }}</p>
    @enderror
    <div class="flex flex-wrap gap-2">
        <flux:button type="button" size="sm" variant="primary" class="min-h-11" wire:click="saveSkill" data-test="save-skill-button">{{ __('Save skill') }}</flux:button>
        <flux:button type="button" size="sm" variant="ghost" class="min-h-11" wire:click="cancelSkill" data-test="cancel-skill-button">{{ __('Cancel') }}</flux:button>
    </div>
</div>
