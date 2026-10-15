{{--
    "Start as guest" form for the landing page (P14.9). Posts to guest.start with an optional
    session name and the registration honeypot. Show it to visitors who are not logged in only.
    Every error comes back in the `guest` error bag (name, honeypot, rate limit, circuit breaker)
    and is shown in one alert.
--}}
@php
    $guestErrors = collect($errors->getBag('guest')->all())->unique()->values();
@endphp
<div {{ $attributes->class(['rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-700 dark:bg-zinc-900']) }} data-test="guest-start">
    <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Just want to try it?') }}</h2>
    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Run a session in a minute, with no account. It is temporary, so sign up whenever you want to keep it.') }}</p>

    @if ($guestErrors->isNotEmpty())
        <div role="alert" class="mt-3 rounded-xl border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100" data-test="guest-start-error">
            @foreach ($guestErrors as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('guest.start') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
        @csrf
        <div class="min-w-0 flex-1">
            <label for="guest-session-name" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Session name (optional)') }}</label>
            <input
                type="text"
                id="guest-session-name"
                name="name"
                maxlength="120"
                value="{{ old('name') }}"
                placeholder="{{ __('Tuesday open play') }}"
                autocomplete="off"
                class="mt-1 min-h-11 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-900 placeholder:text-zinc-500 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder:text-zinc-400"
                data-test="guest-name"
            >
        </div>

        {{-- Honeypot: visually hidden, not type=hidden. People leave it empty. --}}
        <div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">
            <label for="guest_homepage_url">{{ __('Leave this field empty') }}</label>
            <input type="text" id="guest_homepage_url" name="{{ \App\Actions\Fortify\CreateNewUser::HONEYPOT }}" value="" autocomplete="off" tabindex="-1">
        </div>

        <button
            type="submit"
            class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl border-2 border-brand-700 bg-white px-5 py-2 text-base font-semibold text-brand-800 transition hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-brand-400 dark:bg-zinc-900 dark:text-brand-300 dark:hover:bg-brand-950"
            data-test="guest-start-button"
        >{{ __('Start as guest — no sign-up') }}</button>
    </form>

    <p class="mt-3 text-xs text-zinc-600 dark:text-zinc-400" data-test="guest-start-limits">
        {{ __('Guest sessions are temporary: everything is deleted :idle h after the last activity, and never kept longer than :max days. Up to :courts courts and :players players.', [
            'idle' => config('pickleq.guest.idle_hours'),
            'max' => (int) round(config('pickleq.guest.max_hours') / 24),
            'courts' => config('pickleq.guest.max_courts'),
            'players' => config('pickleq.guest.max_checked_in'),
        ]) }}
    </p>
</div>
