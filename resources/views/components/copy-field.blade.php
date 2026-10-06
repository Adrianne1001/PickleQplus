@props(['label', 'value', 'test' => null])

<div x-data="{ copied: false }" class="space-y-1">
    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300" for="copy-{{ $test }}">{{ $label }}</label>
    <div class="flex gap-2">
        <input id="copy-{{ $test }}" type="text" readonly value="{{ $value }}" data-test="{{ $test }}"
            x-ref="field" x-on:focus="$el.select()"
            class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
        <flux:button type="button" icon="clipboard"
            x-on:click="navigator.clipboard.writeText($refs.field.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
            <span x-show="!copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
        </flux:button>
    </div>
</div>
