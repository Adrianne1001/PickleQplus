@props(['id', 'name'])
{{-- A public player name. Highlighted (Alpine `me`) when it is the viewer. --}}
<span x-bind:class="me === @js((string) $id) && 'rounded bg-yellow-200 px-1 font-bold text-zinc-900 dark:bg-yellow-400'" data-player-id="{{ $id }}">{{ $name }}</span>
