@props(['match', 'mixed' => false])

{{-- Two teams of a board match row (see App\Services\SessionBoard). --}}
<div class="relative grid gap-2 sm:grid-cols-2" {{ $attributes }}>
    @foreach (['A', 'B'] as $team)
        <div class="rounded-xl bg-zinc-50 p-3 ring-1 ring-zinc-200 ring-inset dark:bg-zinc-800/60 dark:ring-zinc-700">
            <div class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">{{ __('Team :team', ['team' => $team]) }}</div>
            <ul class="space-y-1.5">
                @foreach ($match['teams'][$team] as $player)
                    <li class="flex items-center justify-between gap-2 text-base text-zinc-900 dark:text-white">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="truncate font-semibold">{{ $player['name'] }}</span>
                            @if ($mixed)
                                <x-gender-marker :gender="$player['gender'] ?? null" />
                            @endif
                        </span>
                        <x-star-rating :stars="$player['stars']" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
