@props(['match', 'mixed' => false])

{{-- Two teams of a board match row (see App\Services\SessionBoard). --}}
<div class="grid gap-2 sm:grid-cols-2" {{ $attributes }}>
    @foreach (['A', 'B'] as $team)
        <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Team :team', ['team' => $team]) }}</div>
            <ul class="space-y-1">
                @foreach ($match['teams'][$team] as $player)
                    <li class="flex items-center justify-between gap-2 text-base">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="truncate font-medium">{{ $player['name'] }}</span>
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
