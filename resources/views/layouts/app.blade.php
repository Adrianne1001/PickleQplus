<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        @if (session('flash'))
            <div class="mb-6" x-data="{ open: true }" x-show="open" role="status" data-test="flash-status">
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.text>{{ session('flash') }}</flux:callout.text>
                    <x-slot name="controls">
                        <flux:button icon="x-mark" variant="ghost" size="sm" x-on:click="open = false" :aria-label="__('Dismiss')" />
                    </x-slot>
                </flux:callout>
            </div>
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
