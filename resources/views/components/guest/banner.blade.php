@props(['club'])

{{--
    Countdown banner for a guest (no account) session. Rendered inside the board, edit and results
    views, so it refreshes with them, and it also counts down on its own (Alpine `guestCountdown`
    in resources/js/guest-expiry.js, every minute), so the edit page doesn't go stale without a poll.
    The server renders the same text first. Reads clubs.guest_expires_at; the cap text comes from
    config. Under 2 hours it turns red and says "Deleting soon" (a text cue, not just colour).
    Renders nothing for a normal club.
--}}
@if ($club->is_guest && $club->guest_expires_at)
    @php
        $expires = $club->guest_expires_at;
        $minutes = max(0, (int) ceil(now()->diffInMinutes($expires, false)));
        $left = match (true) {
            $minutes < 1 => __('a moment'),
            $minutes < 60 => __('about :n min', ['n' => $minutes]),
            default => __('about :n h', ['n' => (int) round($minutes / 60)]),
        };
        $soon = $minutes < 120;
        $texts = ['moment' => __('a moment'), 'minutes' => __('about :n min', ['n' => ':n']), 'hours' => __('about :n h', ['n' => ':n'])];
    @endphp
    <div
        role="status"
        wire:ignore
        wire:key="guest-banner-{{ $expires->timestamp }}"
        x-data="guestCountdown(@js($expires->toIso8601String()), @js($texts))"
        x-bind:class="urgent
            ? 'bg-red-50 text-red-900 ring-red-200 dark:bg-red-950/60 dark:text-red-200 dark:ring-red-900'
            : 'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-200 dark:ring-amber-900'"
        @class([
            'flex flex-col gap-3 rounded-2xl p-4 text-sm ring-1 sm:flex-row sm:items-center sm:justify-between',
            'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-200 dark:ring-amber-900' => ! $soon,
            'bg-red-50 text-red-900 ring-red-200 dark:bg-red-950/60 dark:text-red-200 dark:ring-red-900' => $soon,
        ])
        data-test="guest-banner"
    >
        <div class="flex min-w-0 items-start gap-3">
            <flux:icon.clock class="mt-0.5 size-5 shrink-0" />
            <div class="min-w-0 space-y-1">
                <p class="font-semibold" data-test="guest-banner-countdown">
                    <span data-test="guest-banner-urgent" x-bind:hidden="! urgent" @unless ($soon) hidden @endunless>{{ __('Deleting soon.') }}</span> {{ __('This guest session will be deleted in') }} <span x-text="text">{{ $left }}</span> (<time datetime="{{ $expires->toIso8601String() }}" x-data x-text="new Date($el.getAttribute('datetime')).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })">{{ __('at :time', ['time' => $expires->format('M j, H:i')]) }}</time>).
                </p>
                <p>{{ __('Anything you do on the board extends this, up to :hours h after you started. Players, matches and results are all deleted.', ['hours' => config('pickleq.guest.max_hours')]) }}</p>
            </div>
        </div>
        <a
            href="{{ route('guest.claim', $club) }}"
            class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-brand-700 px-4 py-2 font-semibold text-white shadow-xs hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            data-test="guest-banner-claim"
        >{{ __('Sign up to keep this session') }}</a>
    </div>
@endif
