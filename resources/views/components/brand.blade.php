{{--
    Logo lockup (mark + wordmark) for auth, public and landing pages.
    Props: href (link target, default home), size ('sm'|'md'|'lg'), wordmark (bool, default true).
--}}
@props([
    'href' => null,
    'size' => 'md',
    'wordmark' => true,
])

@php
    $tile = ['sm' => 'size-8 rounded-lg', 'md' => 'size-10 rounded-xl', 'lg' => 'size-14 rounded-2xl'][$size] ?? 'size-10 rounded-xl';
    $icon = ['sm' => 'size-5', 'md' => 'size-6', 'lg' => 'size-9'][$size] ?? 'size-6';
    $text = ['sm' => 'text-base', 'md' => 'text-lg', 'lg' => 'text-2xl'][$size] ?? 'text-lg';
@endphp

<a href="{{ $href ?? route('home') }}" {{ $attributes->class(['inline-flex items-center gap-2.5 font-bold tracking-tight text-zinc-900 dark:text-white']) }}>
    <span class="{{ $tile }} flex shrink-0 items-center justify-center bg-brand-700 shadow-xs">
        <x-app-logo-icon class="{{ $icon }} text-ball" />
    </span>
    @if ($wordmark)
        <span class="{{ $text }}">Pickle<span class="text-brand-700 dark:text-brand-400">Q+</span></span>
    @else
        <span class="sr-only">{{ config('app.name', 'PickleQ+') }}</span>
    @endif
</a>
