@php
    $input = 'mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-lg text-zinc-900 placeholder:text-zinc-500 focus:border-brand-600 focus:outline-2 focus:outline-brand-600 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white dark:placeholder:text-zinc-400';
    $choice = 'flex min-h-12 cursor-pointer items-center gap-2 rounded-xl border-2 border-zinc-200 bg-white px-4 py-3 text-lg text-zinc-900 has-checked:border-brand-600 has-checked:bg-brand-50 has-checked:text-brand-900 has-focus-visible:outline-2 has-focus-visible:outline-brand-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:has-checked:border-brand-400 dark:has-checked:bg-brand-950 dark:has-checked:text-white';
    $primaryBtn = 'min-h-12 rounded-xl bg-brand-700 px-4 py-3 text-lg font-semibold text-white shadow-xs hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
    $secondaryBtn = 'min-h-12 rounded-xl border border-zinc-300 bg-white px-4 py-3 text-lg font-medium text-zinc-900 hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800';
    $errorText = 'mt-1 text-sm font-medium text-red-700 dark:text-red-400';
@endphp

<main
    class="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-6 px-4 pt-6 pb-24"
    x-data="checkinMe()"
    x-on:me-selected.window="remember($event.detail.publicId, $event.detail.playerId)"
    data-test="checkin-page"
>
    <x-guest.expiry-watch :session="$session" public />
    @if ($session === null)
        <div class="flex flex-1 flex-col items-center justify-center text-center" data-test="checkin-closed">
            <x-empty-state icon="lock-closed" :title="__('Check-in is closed')" :description="__('This check-in link is no longer active. Please ask the organizer for the current QR code.')" class="w-full" />
        </div>
    @else
        <header class="space-y-2">
            <x-brand size="sm" />
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-400">{{ __('Check in') }}</p>
            <h1 class="text-3xl font-bold tracking-tight">{{ $session->name }}</h1>
        </header>

        @foreach (['throttle', 'session', 'player'] as $key)
            @error($key)
                <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 font-medium text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100" data-test="checkin-error">{{ $message }}</div>
            @enderror
        @endforeach

        @if ($done)
            <section class="space-y-5 rounded-2xl bg-brand-700 p-6 text-white shadow-lg" role="status" data-test="checkin-done">
                <span class="flex size-14 items-center justify-center rounded-full bg-white/15">
                    <flux:icon icon="check" class="size-8" />
                </span>
                <h2 class="text-3xl font-extrabold tracking-tight">
                    @if ($done['result'] === 'already_checked_in')
                        {{ __("You're already checked in, :name.", ['name' => $done['public_name']]) }}
                    @elseif ($done['result'] === 'returned_from_break')
                        {{ __('Welcome back, :name!', ['name' => $done['public_name']]) }}
                    @else
                        {{ __("You're checked in, :name!", ['name' => $done['public_name']]) }}
                    @endif
                </h2>
                <a href="{{ $queueUrl }}" class="flex min-h-14 items-center justify-center rounded-xl bg-white px-5 py-3 text-lg font-bold text-brand-800 hover:bg-brand-50" data-test="queue-link">{{ __('See the queue') }}</a>
            </section>
        @elseif (! $errors->has('session'))
            @if ($listEmpty)
                <section wire:poll.20s data-test="checkin-no-list">
                    <x-empty-state icon="clipboard-document-list" :title="__('The player list is empty')" :description="__('Ask the organizer to add you to the player list.')" />
                </section>
            @else
                <section class="space-y-4" data-test="checkin-search-section">
                    <div>
                        <label for="search" class="block text-lg font-semibold">{{ __('Find your name') }}</label>
                        <input
                            id="search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            autocomplete="off"
                            placeholder="{{ __('Type at least 2 letters') }}"
                            class="{{ $input }} py-4 text-xl"
                            data-test="checkin-search"
                        />
                    </div>

                    @if ($selected)
                        <form wire:submit="confirm" class="space-y-4 rounded-2xl border-2 border-brand-600 bg-white p-5 shadow-xs dark:border-brand-400 dark:bg-zinc-900" data-test="checkin-confirm">
                            <p class="text-xl font-semibold">
                                {{ __('Check in as :name?', ['name' => $selected['name']]) }}
                            </p>
                            @if ($requiresPassword)
                                <div data-test="checkin-password">
                                    <label for="password" class="block font-medium">{{ __('Session password') }}</label>
                                    <input id="password" type="text" wire:model="password" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" aria-describedby="password-hint" @error('password') aria-invalid="true" @enderror class="{{ $input }}" />
                                    <p id="password-hint" class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Ask the organizer for the password.') }}</p>
                                    @error('password') <p class="{{ $errorText }}" role="alert" data-test="error-password">{{ $message }}</p> @enderror
                                </div>
                            @endif
                            @if ($needsGender)
                                <fieldset data-test="checkin-gender">
                                    <legend class="block font-medium">{{ __('Gender (optional)') }}</legend>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach (['man' => __('Man'), 'woman' => __('Woman'), '' => __('Prefer not to say')] as $value => $label)
                                            <label class="{{ $choice }}">
                                                <input type="radio" wire:model="gender" value="{{ $value }}" class="size-5 accent-brand-700" />
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('gender') <p class="{{ $errorText }}" data-test="error-gender">{{ $message }}</p> @enderror
                                </fieldset>
                            @endif
                            @if ($starsPending && $levels !== [])
                                <fieldset class="space-y-2" data-test="checkin-skill">
                                    <legend class="block font-medium">{{ __('Your skill level (optional)') }}</legend>
                                    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Tap a star to see what it means. Not sure? Skip it and just check in; the organizer can set it later.') }}</p>
                                    <x-star-picker model="stars" :label="__('Your skill level')" :levels="$levels" :clearable="true" :empty="0" />
                                    @error('stars') <p class="{{ $errorText }}" role="alert" data-test="error-stars">{{ $message }}</p> @enderror
                                </fieldset>
                            @endif
                            <div class="flex gap-3">
                                <button type="submit" class="{{ $primaryBtn }} flex-1" data-test="checkin-confirm-button">{{ __('Yes, check me in') }}</button>
                                <button type="button" wire:click="cancelSelect" class="{{ $secondaryBtn }}" data-test="checkin-cancel-button">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <ul class="divide-y divide-zinc-200 overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900" data-test="checkin-results">
                            @forelse ($results as $row)
                                <li wire:key="res-{{ $row['id'] }}">
                                    <button type="button" wire:click="select(@js($row['id']))" class="flex min-h-16 w-full items-center justify-between gap-3 px-4 py-4 text-start text-lg hover:bg-zinc-50 focus-visible:bg-zinc-50 dark:hover:bg-zinc-800 dark:focus-visible:bg-zinc-800" data-test="checkin-result">
                                        <span class="font-medium">
                                            {{ $row['name'] }}
                                        </span>
                                        @if ($row['status'])
                                            <span class="shrink-0 rounded-full bg-brand-50 px-3 py-1 text-sm font-semibold text-brand-800 dark:bg-brand-950 dark:text-brand-300" data-test="result-status">
                                                {{ match ($row['status']) { 'playing' => __('Playing'), 'break' => __('On break'), default => __('Checked in') } }}
                                            </span>
                                        @else
                                            <flux:icon icon="chevron-right" class="size-5 shrink-0 text-zinc-500" />
                                        @endif
                                    </button>
                                </li>
                            @empty
                                <li class="px-4 py-4 text-lg text-zinc-700 dark:text-zinc-300" data-test="checkin-no-match">{{ __('No match. Check the spelling.') }} <strong class="font-semibold">{{ __('Not on the list? Ask the organizer.') }}</strong></li>
                            @endforelse
                        </ul>
                    @endif
                </section>
            @endif
        @endif
    @endif
</main>
