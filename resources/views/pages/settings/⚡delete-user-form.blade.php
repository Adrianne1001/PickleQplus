<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-8 space-y-4 rounded-2xl border border-red-300 bg-red-50/50 p-5 dark:border-red-900 dark:bg-red-950/20">
    <div class="relative">
        <flux:heading class="text-red-700 dark:text-red-400">{{ __('Delete account') }}</flux:heading>
        <flux:subheading>{{ __('Delete your account and all of its resources') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" data-test="delete-user-button">
            {{ __('Delete account') }}
        </flux:button>
    </flux:modal.trigger>

    <livewire:pages::settings.delete-user-modal />
</section>
