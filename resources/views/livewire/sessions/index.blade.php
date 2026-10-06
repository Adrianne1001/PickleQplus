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

    <flux:select wire:model.live="status" :label="__('Status')" class="max-w-48" data-test="status-filter">
        <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
        <flux:select.option value="live">{{ __('Live') }}</flux:select.option>
        <flux:select.option value="ended">{{ __('Ended') }}</flux:select.option>
    </flux:select>

    <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="sessions-list">
        @forelse ($this->sessions as $session)
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-900" wire:key="session-{{ $session->id }}" data-test="session-row">
                <a href="{{ route('clubs.sessions.show', [$club, $session]) }}" wire:navigate class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ $session->name }}</div>
                    <div class="text-sm text-zinc-500">{{ $session->date->format('D, j M Y') }}</div>
                </a>
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <span>{{ trans_choice(':count court|:count courts', $session->courts) }}</span>
                    @if ($session->isEnded())
                        <span data-test="session-matches">{{ trans_choice(':count match|:count matches', $session->done_matches_count) }}</span>
                        <span data-test="session-players">{{ trans_choice(':count player|:count players', $session->players_count) }}</span>
                    @else
                        <span>{{ __(':count checked in', ['count' => $session->checked_in_count]) }}</span>
                    @endif
                    <x-session-status-badge :status="$session->status" />
                    @if ($session->isEnded())
                        <flux:button size="sm" :href="route('clubs.sessions.results', [$club, $session])" wire:navigate data-test="results-link">{{ __('Results') }}</flux:button>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-4 py-8 text-center text-zinc-500" data-test="sessions-empty">
                {{ __('No sessions yet. Create one to start an open play night.') }}
            </div>
        @endforelse
    </div>

    {{ $this->sessions->links() }}
</section>
