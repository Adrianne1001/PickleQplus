<section class="w-full max-w-5xl space-y-6">
    <x-page-header :eyebrow="$club->name" :title="__('Sessions')" :description="__('Open play nights: start one, run the board, and review the results.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" class="min-h-11" :href="route('clubs.sessions.create', $club)" wire:navigate data-test="new-session-button">
                {{ __('New session') }}
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-card :padding="false">
        <x-slot:actions>
            <flux:select wire:model.live="status" :label="__('Status')" class="min-w-40" data-test="status-filter">
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
                <flux:select.option value="live">{{ __('Live') }}</flux:select.option>
                <flux:select.option value="ended">{{ __('Ended') }}</flux:select.option>
            </flux:select>
        </x-slot:actions>

        <div class="divide-y divide-zinc-200 dark:divide-zinc-700" data-test="sessions-list">
            @forelse ($this->sessions as $session)
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50" wire:key="session-{{ $session->id }}" data-test="session-row">
                    <a href="{{ route('clubs.sessions.show', [$club, $session]) }}" wire:navigate class="min-w-0 flex-1 focus-visible:outline-2 focus-visible:outline-brand-600">
                        <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $session->name }}</div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $session->date->format('D, j M Y') }}</div>
                    </a>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-zinc-600 dark:text-zinc-400">
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
                <div class="p-5" data-test="sessions-empty">
                    <x-empty-state icon="calendar-days" :title="__('No sessions yet.')" :description="__('Create one to start an open play night.')">
                        <flux:button variant="primary" icon="plus" :href="route('clubs.sessions.create', $club)" wire:navigate>{{ __('New session') }}</flux:button>
                    </x-empty-state>
                </div>
            @endforelse
        </div>
    </x-card>

    {{ $this->sessions->links() }}
</section>
