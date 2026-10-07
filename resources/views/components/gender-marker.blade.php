@props(['gender' => null])

{{-- Staff-only M/W marker. Never render this on public pages. --}}
@if ($gender === 'man' || $gender === 'woman')
    <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $gender === 'man' ? 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-100' : 'bg-pink-100 text-pink-800 dark:bg-pink-900 dark:text-pink-100' }}" title="{{ $gender === 'man' ? __('Man') : __('Woman') }}" data-test="gender-marker" data-gender="{{ $gender }}">{{ $gender === 'man' ? 'M' : 'W' }}</span>
@endif
