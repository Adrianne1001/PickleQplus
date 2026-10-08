{{--
    Page header for every staff page.
    Props: title (required), description, eyebrow (small label above the title, e.g. club name),
           back (URL; renders a "Back" link), backLabel.
    Slots: default = nothing; "actions" = primary buttons (right on desktop, below on phones).
--}}
@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'back' => null,
    'backLabel' => null,
])

<header {{ $attributes->class(['mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between lg:mb-8']) }} data-ui="page-header">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                <flux:icon.chevron-left class="size-4" />
                {{ $backLabel ?? __('Back') }}
            </a>
        @endif

        @if ($eyebrow)
            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-400">{{ $eyebrow }}</p>
        @endif

        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ $title }}</h1>

        @if ($description)
            <p class="mt-1.5 max-w-2xl text-sm text-zinc-600 sm:text-base dark:text-zinc-400">{{ $description }}</p>
        @endif

        {{ $slot }}
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
