<section class="w-full max-w-4xl space-y-8">
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('clubs.sessions.show', [$club, $session])" wire:navigate>{{ __('Back to session') }}</flux:button>
        <flux:heading size="xl" level="1" class="mt-2">{{ __('DUPR export') }}</flux:heading>
        <flux:subheading>
            {{ $session->name }} &middot; {{ $session->date->format('D, j M Y') }}
            @if ($club->dupr_club_id)
                &middot; {{ __('DUPR club ID: :id', ['id' => $club->dupr_club_id]) }}
            @endif
        </flux:subheading>
        @if ($club->dupr_club_id)
            <flux:text class="mt-1">{{ __('Upload the downloaded CSV to this club on DUPR.') }}</flux:text>
        @endif
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="export-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <div>
            <div class="text-3xl font-semibold tabular-nums" data-test="eligible-count">{{ $summary->eligibleCount() }}</div>
            <flux:text>{{ __('matches ready to export') }}@if ($summary->skippedCount() > 0) &middot; {{ __(':n skipped', ['n' => $summary->skippedCount()]) }} @endif</flux:text>
        </div>
        @if (! $session->isEnded())
            <flux:text class="font-medium" data-test="end-session-notice">{{ __('End the session to export') }}</flux:text>
        @else
            <flux:button variant="primary" icon="arrow-down-tray" class="min-h-12" wire:click="export" wire:loading.attr="disabled" wire:target="export" :disabled="! $summary->hasEligible()" data-test="export-button">
                {{ __('Export CSV') }}
            </flux:button>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Eligible matches') }}</flux:heading>
        @forelse ($summary->eligible as $match)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="eligible-{{ $match->id }}" data-test="eligible-row">
                <div>
                    <div class="text-sm text-zinc-500">{{ $match->court_no ? __('Court :n', ['n' => $match->court_no]) : __('No court') }}</div>
                    <div>{{ \App\Livewire\Sessions\DuprExportPage::teamNames($match, 'A') }} <span class="text-zinc-500">{{ __('vs') }}</span> {{ \App\Livewire\Sessions\DuprExportPage::teamNames($match, 'B') }}</div>
                </div>
                <span class="text-xl font-semibold tabular-nums">{{ $match->team_a_score }} - {{ $match->team_b_score }}</span>
            </div>
        @empty
            <flux:text>{{ __('Nothing is eligible for export.') }}</flux:text>
        @endforelse
    </div>

    @if ($summary->skipped !== [])
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Skipped matches') }}</flux:heading>
            @foreach ($summary->skipped as $skipped)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="skipped-{{ $skipped->match->id }}" data-test="skipped-row">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span>{{ \App\Livewire\Sessions\DuprExportPage::teamNames($skipped->match, 'A') }} <span class="text-zinc-500">{{ __('vs') }}</span> {{ \App\Livewire\Sessions\DuprExportPage::teamNames($skipped->match, 'B') }}</span>
                        <flux:badge size="sm">{{ $skipped->reason->label() }}</flux:badge>
                    </div>
                    @if ($skipped->missingNames() !== [])
                        <flux:text class="mt-1">{{ __('Missing DUPR ID: :names', ['names' => implode(', ', $skipped->missingNames())]) }}</flux:text>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($summary->missingPlayers !== [])
        <div class="space-y-3" data-test="missing-players">
            <flux:heading size="lg">{{ __('Players missing a DUPR ID') }}</flux:heading>
            @foreach ($summary->missingPlayers as $missing)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="missing-{{ $missing->player->id }}" data-test="missing-player-row">
                    <span>{{ $missing->player->name }} <span class="text-zinc-500">&middot; {{ trans_choice('blocks :count match|blocks :count matches', $missing->blockedMatches) }}</span></span>
                    <flux:button size="sm" :href="route('clubs.players.index', [$club, 'search' => $missing->player->name])" wire:navigate>{{ __('Edit player') }}</flux:button>
                </div>
            @endforeach
        </div>
    @endif

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Export history') }}</flux:heading>
        @forelse ($history as $past)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="export-{{ $past->id }}" data-test="history-row">
                <div>
                    <div>{{ $past->created_at->format('j M Y, g:i A') }}</div>
                    <flux:text>{{ $past->user?->name ?? __('deleted user') }} &middot; {{ trans_choice(':count match|:count matches', $past->match_count) }}</flux:text>
                </div>
                <flux:button size="sm" icon="arrow-down-tray" :href="route('clubs.sessions.dupr.download', [$club, $session, $past->id])" data-test="download-link">{{ __('Download') }}</flux:button>
            </div>
        @empty
            <flux:text>{{ __('No exports yet.') }}</flux:text>
        @endforelse
    </div>
</section>
