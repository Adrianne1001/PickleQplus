{{--
    Empty state: icon, headline, one line of help and (optionally) the next action.
    Props: icon (Flux/Heroicon name, default 'sparkles'), title (required), description.
    Slot: the action, usually one <flux:button variant="primary">.
--}}
@props([
    'icon' => 'sparkles',
    'title',
    'description' => null,
])

<div {{ $attributes->class(['flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-700']) }} data-ui="empty-state">
    <span class="mb-4 flex size-12 items-center justify-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-400">
        <flux:icon :icon="$icon" class="size-6" />
    </span>
    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
