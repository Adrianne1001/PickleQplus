<section class="w-full max-w-4xl space-y-6 lg:space-y-8">
    <x-page-header
        :eyebrow="$club->name"
        :title="__('DUPR export')"
        :description="$session->name.' · '.$session->date->format('D, j M Y').($club->dupr_club_id ? ' · '.__('DUPR club ID: :id', ['id' => $club->dupr_club_id]) : '')"
        :back="route('clubs.sessions.show', [$club, $session])"
        :back-label="__('Back to session')"
    >
        @if ($club->dupr_club_id)
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Upload the downloaded CSV to this club on DUPR.') }}</p>
        @endif
    </x-page-header>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="export-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-4xl font-bold tabular-nums text-zinc-900 dark:text-white" data-test="eligible-count">{{ $summary->eligibleCount() }}</div>
                <flux:text>{{ __('matches ready to export') }}@if ($summary->skippedCount() > 0) &middot; {{ __(':n skipped', ['n' => $summary->skippedCount()]) }} @endif</flux:text>
            </div>
            @if (! $session->isEnded())
                <flux:text class="font-medium text-amber-800 dark:text-amber-300" data-test="end-session-notice">{{ __('End the session to export') }}</flux:text>
            @else
                <flux:button variant="primary" icon="arrow-down-tray" class="min-h-12" wire:click="export" wire:loading.attr="disabled" wire:target="export" :disabled="! $summary->hasEligible()" data-test="export-button">
                    {{ __('Export CSV') }}
                </flux:button>
            @endif
        </div>
    </x-card>

    <x-card :title="__('Eligible matches')" :padding="false">
        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($summary->eligible as $match)
                <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" wire:key="eligible-{{ $match->id }}" data-test="eligible-row">
                    <div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $match->court_no ? __('Court :n', ['n' => $match->court_no]) : __('No court') }}</div>
                        <div class="text-zinc-900 dark:text-white">{{ \App\Livewire\Sessions\DuprExportPage::teamNames($match, 'A') }} <span class="text-zinc-600 dark:text-zinc-400">{{ __('vs') }}</span> {{ \App\Livewire\Sessions\DuprExportPage::teamNames($match, 'B') }}</div>
                    </div>
                    <span class="text-xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $match->team_a_score }} - {{ $match->team_b_score }}</span>
                </div>
            @empty
                <div class="p-5">
                    <x-empty-state icon="document-arrow-down" :title="__('Nothing is eligible for export.')" :description="__('Finished matches where every player has a DUPR ID appear here.')" />
                </div>
            @endforelse
        </div>
    </x-card>

    @if ($summary->skipped !== [])
        <x-card :title="__('Skipped matches')" :padding="false">
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($summary->skipped as $skipped)
                    <div class="px-5 py-3" wire:key="skipped-{{ $skipped->match->id }}" data-test="skipped-row">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-zinc-900 dark:text-white">{{ \App\Livewire\Sessions\DuprExportPage::teamNames($skipped->match, 'A') }} <span class="text-zinc-600 dark:text-zinc-400">{{ __('vs') }}</span> {{ \App\Livewire\Sessions\DuprExportPage::teamNames($skipped->match, 'B') }}</span>
                            <flux:badge size="sm" color="amber">{{ $skipped->reason->label() }}</flux:badge>
                        </div>
                        @if ($skipped->missingNames() !== [])
                            <flux:text class="mt-1">{{ __('Missing DUPR ID: :names', ['names' => implode(', ', $skipped->missingNames())]) }}</flux:text>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    @if ($summary->missingPlayers !== [])
        <x-card :title="__('Players missing a DUPR ID')" :padding="false" data-test="missing-players">
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($summary->missingPlayers as $missing)
                    <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" wire:key="missing-{{ $missing->player->id }}" data-test="missing-player-row">
                        <span class="text-zinc-900 dark:text-white">{{ $missing->player->name }} <span class="text-zinc-600 dark:text-zinc-400">&middot; {{ trans_choice('blocks :count match|blocks :count matches', $missing->blockedMatches) }}</span></span>
                        <flux:button size="sm" :href="route('clubs.players.index', [$club, 'search' => $missing->player->name])" wire:navigate>{{ __('Edit player') }}</flux:button>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif

    <x-card :title="__('Export history')" :padding="false">
        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($history as $past)
                <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" wire:key="export-{{ $past->id }}" data-test="history-row">
                    <div>
                        <div class="text-zinc-900 dark:text-white">{{ $past->created_at->format('j M Y, g:i A') }}</div>
                        <flux:text>{{ $past->user?->name ?? __('deleted user') }} &middot; {{ trans_choice(':count match|:count matches', $past->match_count) }}</flux:text>
                    </div>
                    <flux:button size="sm" icon="arrow-down-tray" :href="route('clubs.sessions.dupr.download', [$club, $session, $past->id])" data-test="download-link">{{ __('Download') }}</flux:button>
                </div>
            @empty
                <div class="p-5">
                    <x-empty-state icon="clock" :title="__('No exports yet.')" :description="__('Each CSV you export is kept here so you can download it again.')" />
                </div>
            @endforelse
        </div>
    </x-card>
</section>
