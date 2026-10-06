<section class="w-full max-w-4xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="flex flex-wrap items-center gap-3">
                {{ $session->name }}
                <x-session-status-badge :status="$session->status" />
            </flux:heading>
            <flux:subheading>{{ $session->date->format('D, j M Y') }}</flux:subheading>
        </div>
        <flux:button icon="arrow-left" :href="route('clubs.sessions.show', [$club, $session])" wire:navigate>{{ __('Back to session') }}</flux:button>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" data-test="standings-heading">
            {{ $session->isEnded() ? __('Final standings') : __('Standings so far') }}
        </flux:heading>
        <x-stats.table :rows="$standings" data-test="standings-table" />
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Match log') }}</flux:heading>
        <x-stats.match-log :matches="$log" data-test="match-log" />
    </div>
</section>
