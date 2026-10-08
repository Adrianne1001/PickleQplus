@props(['highlights'])

{{-- Highlight cards. Each one is hidden when it doesn't apply; the section hides when none do. --}}
@php
    $h = $highlights;
    $teams = fn (array $m): string => implode(' & ', $m['team_a']).' '.__('vs').' '.implode(' & ', $m['team_b']);
    $cards = [];
    if ($h['most_games']) {
        $cards[] = ['key' => 'most-games', 'icon' => '🔥', 'label' => __('Most games'), 'value' => (string) $h['most_games']['value'], 'unit' => __('games'), 'detail' => $h['most_games']['name'], 'tone' => 'bg-orange-50 text-orange-900 ring-orange-200 dark:bg-orange-950/50 dark:text-orange-100 dark:ring-orange-900'];
    }
    if ($h['best_point_diff']) {
        $cards[] = ['key' => 'best-point-diff', 'icon' => '🎯', 'label' => __('Best point diff'), 'value' => '+'.$h['best_point_diff']['value'], 'unit' => __('points'), 'detail' => $h['best_point_diff']['name'], 'tone' => 'bg-sky-50 text-sky-900 ring-sky-200 dark:bg-sky-950/50 dark:text-sky-100 dark:ring-sky-900'];
    }
    if ($h['closest_match']) {
        $cards[] = ['key' => 'closest-match', 'icon' => '😮', 'label' => __('Closest match'), 'value' => $h['closest_match']['score_a'].'–'.$h['closest_match']['score_b'], 'unit' => null, 'detail' => $teams($h['closest_match']), 'tone' => 'bg-purple-50 text-purple-900 ring-purple-200 dark:bg-purple-950/50 dark:text-purple-100 dark:ring-purple-900'];
    }
    if ($h['biggest_win']) {
        $cards[] = ['key' => 'biggest-win', 'icon' => '💥', 'label' => __('Biggest win'), 'value' => $h['biggest_win']['score_a'].'–'.$h['biggest_win']['score_b'], 'unit' => null, 'detail' => $teams($h['biggest_win']), 'tone' => 'bg-rose-50 text-rose-900 ring-rose-200 dark:bg-rose-950/50 dark:text-rose-100 dark:ring-rose-900'];
    }
@endphp

@if ($cards !== [])
    <section class="space-y-3" aria-labelledby="highlights-h" data-test="results-highlights">
        <h2 id="highlights-h" class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Highlights') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($cards as $i => $c)
                <article class="rs-fade-up rounded-2xl p-4 ring-1 {{ $c['tone'] }}" style="--rs-delay: {{ $i * 0.08 }}s" data-test="highlight-{{ $c['key'] }}">
                    <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider"><span class="text-lg leading-none" aria-hidden="true">{{ $c['icon'] }}</span>{{ $c['label'] }}</p>
                    <p class="mt-2 text-4xl font-extrabold tabular-nums leading-none">{{ $c['value'] }}@if ($c['unit'])<span class="ms-1.5 text-base font-semibold">{{ $c['unit'] }}</span>@endif</p>
                    <p class="mt-2 text-sm font-semibold [overflow-wrap:anywhere]">{{ $c['detail'] }}</p>
                </article>
            @endforeach
        </div>
    </section>
@endif
