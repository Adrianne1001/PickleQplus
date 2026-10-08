{{--
    Card / section wrapper: white surface, soft border and shadow, rounded-2xl.
    Props: title, description (optional heading block), padding (bool, default true; set false for tables/lists).
    Slots: default = body; "actions" = buttons in the heading row; "footer" = bottom strip.
--}}
@props([
    'title' => null,
    'description' => null,
    'padding' => true,
])

<section {{ $attributes->class(['overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900']) }} data-ui="card">
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class(['p-5' => $padding])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-zinc-100 bg-zinc-50 px-5 py-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400">
            {{ $footer }}
        </div>
    @endisset
</section>
