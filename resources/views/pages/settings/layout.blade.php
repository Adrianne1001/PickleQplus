<div class="flex items-start gap-8 max-md:flex-col max-md:gap-4">
    <div class="w-full md:w-56 md:shrink-0">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item icon="user" :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item icon="shield-check" :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item icon="swatch" :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <div class="w-full max-w-2xl flex-1 space-y-6">
        <x-card :title="$heading ?? null" :description="$subheading ?? null">
            {{ $slot }}
        </x-card>
    </div>
</div>
