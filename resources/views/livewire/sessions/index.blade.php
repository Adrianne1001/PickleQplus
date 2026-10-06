<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Sessions') }}</flux:heading>
            <flux:subheading>{{ $club->name }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('clubs.sessions.create', $club)" wire:navigate data-test="new-session-button">
            {{ __('New session') }}
        </flux:button>
    </div>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="sessions-list">
        @forelse ($this->sessions as $session)
            <a
                href="{{ route('clubs.sessions.show', [$club, $session]) }}"
                wire:navigate
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-900"
                data-test="session-row"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ $session->name }}</div>
                    <div class="text-sm text-zinc-500">{{ $session->date->format('D, j M Y') }}</div>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <span>{{ trans_choice(':count court|:count courts', $session->courts) }}</span>
                    <span>{{ __(':count checked in', ['count' => $session->checked_in_count]) }}</span>
                    <x-session-status-badge :status="$session->status" />
                </div>
            </a>
        @empty
            <div class="px-4 py-8 text-center text-zinc-500" data-test="sessions-empty">
                {{ __('No sessions yet. Create one to start an open play night.') }}
            </div>
        @endforelse
    </div>
</section>
