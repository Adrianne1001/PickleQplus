<section class="w-full max-w-5xl space-y-6 lg:space-y-8">
    <x-page-header
        :eyebrow="$club->name"
        :title="$session->name"
        :description="$session->date->format('D, j M Y')"
        :back="route('clubs.sessions.show', [$club, $session])"
        :back-label="__('Back to session')"
    >
        <div class="mt-3"><x-session-status-badge :status="$session->status" /></div>
    </x-page-header>

    <div class="space-y-3">
        <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white" data-test="standings-heading">
            {{ $session->isEnded() ? __('Final standings') : __('Standings so far') }}
        </h2>
        <x-stats.table :rows="$standings" data-test="standings-table" />
    </div>

    <div class="space-y-3">
        <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Match log') }}</h2>
        <x-stats.match-log :matches="$log" data-test="match-log" />
    </div>
</section>
