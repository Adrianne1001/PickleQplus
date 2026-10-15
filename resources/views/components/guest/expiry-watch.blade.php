@props(['session' => null, 'club' => null, 'public' => false])

{{--
    Live expiry for guest pages (P14.9). When a guest club is purged the server broadcasts
    `session.expired` on the session's public channel; this element makes the browser listen
    for it and go to the expired page. The data-guest-expiry attributes also switch on the
    Livewire request hook (resources/js/guest-expiry.js): on board pages a 410 goes to the same
    page; with `public` (queue, TV, check-in) a 404 does too, because the club itself may be gone.
    Pass `club` when the page already has it, so no relation is loaded here.
    Renders nothing for a normal club, so nothing changes there.
--}}
@php
    $club ??= $session?->relationLoaded('club') ? $session->club : null;
@endphp
@if ($session && $club?->is_guest)
    <div
        hidden
        wire:ignore
        data-guest-expiry="{{ route('guest.expired') }}"
        data-guest-expiry-on="{{ $public ? '404,410' : '410' }}"
        data-test="guest-expiry-watch"
        x-data="guestExpiry(@js('play-session.'.$session->public_id), @js(route('guest.expired')))"
    ></div>
@endif
