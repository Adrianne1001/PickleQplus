@props(['results', 'standings', 'staff' => false, 'live' => false])

{{--
    The whole results layout, shared by the staff page and the public ended page:
    hero + podium, neighbours, share panel (slot "share"), highlights, standings and match log.
    Staff rows may carry `url` (profile link); the public log has done matches only.
--}}
@php
    $caption = implode(' · ', [$results['session']['club_name'], $results['session']['name'], $results['session']['date']->format('M j, Y'), 'PickleQ+']);
@endphp
<div {{ $attributes->class(['w-full space-y-6 lg:space-y-8']) }} data-test="results-page">
    <x-results.hero :results="$results" :live="$live">
        <x-results.podium :podium="$results['podium']" />
    </x-results.hero>

    @isset($neighbours)
        {{ $neighbours }}
    @endisset

    @isset($share)
        {{ $share }}
    @endisset

    <x-results.highlights :highlights="$results['highlights']" />

    <section class="space-y-3" @if ($staff) data-test="standings-section" @else data-test="public-standings" @endif>
        <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white" data-test="standings-heading">
            {{ $live ? __('Standings so far') : __('Final standings') }}
        </h2>
        <x-stats.table :rows="$standings" :caption="$caption" data-test="standings-table" />
    </section>

    <section class="space-y-3" @unless ($staff) data-test="public-match-log" @endunless>
        <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ $staff ? __('Match log') : __('Matches') }}</h2>
        <x-stats.match-log :matches="$results['matches']" :session-date="$results['session']['date']" :caption="$caption" :data-test="$staff ? 'match-log' : 'public-match-log-table'" />
    </section>
</div>
