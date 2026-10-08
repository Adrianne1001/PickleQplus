<section class="w-full max-w-5xl space-y-4 lg:space-y-6">
    <a href="{{ route('clubs.sessions.show', [$club, $session]) }}" wire:navigate class="inline-flex min-h-10 items-center gap-1 text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
        <flux:icon.chevron-left class="size-4" />
        {{ __('Back to session') }}
    </a>

    <x-results.body :results="$results" :standings="$standings" staff :live="! $session->isEnded()">
        @if ($previous || $next)
            <x-slot:neighbours>
                <x-results.neighbours :previous="$previous" :next="$next" :all-url="route('clubs.sessions.index', $club)" :all-label="__('All sessions')" />
            </x-slot:neighbours>
        @endif

        <x-slot:share>
            @if ($share)
                <x-results.share-panel :share-url="$share['url']" :share-svg="$share['svg']" :title="$share['title']" :text="$share['text']" :gif-url="$share['gif']" :png-url="$share['png']" />
            @elseif ($session->isEnded())
                <p class="rounded-2xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-400" data-test="share-unavailable">{{ __('This session has no public link yet.') }}</p>
            @else
                <p class="flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm font-medium text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-200 dark:ring-amber-900" data-test="share-after-end">
                    <flux:icon.share class="mt-0.5 size-5 shrink-0" />
                    {{ __('Sharing opens once the session ends. The podium image, GIF and public link appear here then.') }}
                </p>
            @endif
        </x-slot:share>
    </x-results.body>
</section>
