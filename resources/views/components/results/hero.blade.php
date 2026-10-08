@props(['results', 'live' => false])

{{--
    Results hero: club, session, date and the four headline numbers. The default slot (the podium)
    renders at the bottom. Always a dark brand gradient, so it screenshots the same in both themes.
--}}
@php
    $session = $results['session'];
    $totals = $results['totals'];
    $stats = [
        [__('Matches'), $totals['matches']],
        [__('Players'), $totals['players']],
        [__('Points'), $totals['points']],
        [__('Courts'), $totals['courts']],
    ];
    $confetti = [
        ['8%', '0s', 'bg-amber-300', '30px', '420deg'], ['16%', '0.5s', 'bg-white', '-24px', '-380deg'],
        ['24%', '0.2s', 'bg-brand-300', '18px', '520deg'], ['33%', '0.9s', 'bg-orange-300', '-30px', '600deg'],
        ['42%', '0.1s', 'bg-white', '26px', '-460deg'], ['50%', '0.7s', 'bg-amber-300', '-18px', '380deg'],
        ['58%', '0.35s', 'bg-brand-300', '34px', '-540deg'], ['66%', '1.1s', 'bg-orange-300', '-26px', '460deg'],
        ['74%', '0.25s', 'bg-white', '20px', '-420deg'], ['82%', '0.8s', 'bg-amber-300', '-32px', '560deg'],
        ['90%', '0.45s', 'bg-brand-300', '22px', '-500deg'], ['96%', '1s', 'bg-orange-300', '-20px', '440deg'],
    ];
@endphp

<section {{ $attributes->class(['relative isolate overflow-hidden rounded-3xl bg-linear-to-br from-brand-700 via-brand-800 to-brand-950 text-white shadow-lg ring-1 ring-brand-900/40']) }} aria-labelledby="results-title" data-test="results-hero">
    {{-- Decoration: soft glows and confetti --}}
    <div class="pointer-events-none absolute -end-16 -top-16 -z-10 size-64 rounded-full bg-ball/25 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-24 -start-16 -z-10 size-72 rounded-full bg-brand-400/20 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true" data-test="confetti">
        @foreach ($confetti as [$x, $delay, $color, $sway, $spin])
            <span class="rs-confetti {{ $color }}" style="--rs-x: {{ $x }}; --rs-delay: {{ $delay }}; --rs-sway: {{ $sway }}; --rs-spin: {{ $spin }}"></span>
        @endforeach
    </div>

    <div class="px-5 pb-6 pt-7 sm:px-8 sm:pt-9">
        <div class="flex flex-wrap items-center gap-2">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-100">{{ $session['club_name'] }}</p>
            @if ($live)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-semibold text-white">
                    <span class="size-2 animate-pulse rounded-full bg-ball" aria-hidden="true"></span>{{ __('Live, so far') }}
                </span>
            @endif
        </div>
        <h1 id="results-title" class="rs-fade-up mt-2 text-balance text-3xl font-extrabold leading-tight tracking-tight sm:text-5xl" data-test="results-title">{{ $session['name'] }}</h1>
        <p class="mt-1 text-sm font-medium text-brand-100 sm:text-base">{{ $session['date']->format('l, j F Y') }}</p>

        <dl class="mt-6 grid grid-cols-4 gap-2 sm:gap-4" data-test="results-totals">
            @foreach ($stats as $i => [$label, $value])
                <div class="rs-fade-up rounded-2xl bg-white/10 px-1 py-3 text-center ring-1 ring-white/15 sm:py-4" style="--rs-delay: {{ 0.1 + $i * 0.08 }}s">
                    <dd class="text-2xl font-extrabold tabular-nums leading-none sm:text-4xl">{{ number_format($value) }}</dd>
                    <dt class="mt-1.5 text-[0.65rem] font-semibold uppercase tracking-wider text-brand-100 sm:text-xs">{{ $label }}</dt>
                </div>
            @endforeach
        </dl>
    </div>

    {{ $slot }}
</section>
