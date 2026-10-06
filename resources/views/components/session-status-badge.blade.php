@props(['status'])

@php
    $color = match ($status->value) {
        'live' => 'green',
        'draft' => 'amber',
        default => 'zinc',
    };
@endphp

<flux:badge :color="$color" size="sm" data-test="session-status">{{ __(ucfirst($status->value)) }}</flux:badge>
