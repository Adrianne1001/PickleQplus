@props(['status'])

@php
    $color = match ($status->value) {
        'live' => 'green',
        'draft' => 'amber',
        default => 'zinc',
    };
@endphp

<flux:badge :color="$color" size="sm" data-test="session-status">
    @if ($status->value === 'live')
        <span class="mr-1 inline-block size-1.5 rounded-full bg-green-600 dark:bg-green-400" aria-hidden="true"></span>
    @endif
    {{ __(ucfirst($status->value)) }}
</flux:badge>
