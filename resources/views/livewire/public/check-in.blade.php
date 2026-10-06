<main
    class="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-5 px-4 py-6"
    x-data="checkinMe()"
    x-on:me-selected.window="remember($event.detail.publicId, $event.detail.playerId)"
    data-test="checkin-page"
>
    @if ($session === null)
        <div data-test="checkin-closed">
            <h1 class="text-3xl font-bold">{{ __('Check-in is closed') }}</h1>
            <p class="mt-2 text-lg text-zinc-600 dark:text-zinc-300">{{ __('This check-in link is no longer active. Please ask the organizer for the current QR code.') }}</p>
        </div>
    @else
        <header>
            <h1 class="text-2xl font-bold">{{ $session->name }}</h1>
            <p class="text-zinc-600 dark:text-zinc-300">{{ __('Check in') }}</p>
        </header>

        @foreach (['throttle', 'session', 'player'] as $key)
            @error($key)
                <div role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3 text-red-900 dark:bg-red-950 dark:text-red-100" data-test="checkin-error">{{ $message }}</div>
            @enderror
        @endforeach

        @if ($done)
            <section class="space-y-3 rounded-xl bg-green-600 p-5 text-white" role="status" data-test="checkin-done">
                <h2 class="text-2xl font-bold">
                    @if ($done['result'] === 'already_checked_in')
                        {{ __("You're already checked in, :name.", ['name' => $done['public_name']]) }}
                    @elseif ($done['result'] === 'returned_from_break')
                        {{ __('Welcome back, :name!', ['name' => $done['public_name']]) }}
                    @else
                        {{ __("You're checked in, :name!", ['name' => $done['public_name']]) }}
                    @endif
                </h2>
                <a href="{{ $queueUrl }}" class="inline-block rounded-lg bg-white px-5 py-3 text-lg font-semibold text-green-800" data-test="queue-link">{{ __('See the queue') }}</a>
            </section>
        @elseif (! $errors->has('session'))
            @if (! $registering)
                <section class="space-y-3" data-test="checkin-search-section">
                    <label for="search" class="block text-lg font-semibold">{{ __('Find your name') }}</label>
                    <input
                        id="search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        autocomplete="off"
                        placeholder="{{ __('Type at least 2 letters') }}"
                        class="w-full rounded-lg border border-zinc-400 bg-white px-4 py-3 text-lg text-zinc-900"
                        data-test="checkin-search"
                    />

                    @if ($selected)
                        <div class="space-y-3 rounded-xl border-2 border-green-600 p-4" data-test="checkin-confirm">
                            <p class="text-xl font-semibold">
                                {{ __('Check in as :name?', ['name' => $selected['name']]) }}
                                @if ($selected['nickname'])
                                    <span class="font-normal text-zinc-500">"{{ $selected['nickname'] }}"</span>
                                @endif
                            </p>
                            @if ($selected['nickname'] === null)
                                <div>
                                    <label for="nickname" class="block font-medium">{{ __('Nickname (optional)') }}</label>
                                    <input id="nickname" type="text" wire:model="nickname" maxlength="20" autocomplete="off" class="mt-1 w-full rounded-lg border border-zinc-400 bg-white px-4 py-3 text-lg text-zinc-900" data-test="checkin-nickname" />
                                    <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Shown on the public queue and TV instead of your full name.') }}</p>
                                    @error('nickname') <p class="text-red-600" data-test="error-nickname">{{ $message }}</p> @enderror
                                </div>
                            @endif
                            <div class="flex gap-3">
                                <button type="button" wire:click="confirm" class="flex-1 rounded-lg bg-green-600 px-4 py-3 text-lg font-semibold text-white" data-test="checkin-confirm-button">{{ __('Yes, check me in') }}</button>
                                <button type="button" wire:click="cancelSelect" class="rounded-lg border border-zinc-400 px-4 py-3 text-lg">{{ __('Cancel') }}</button>
                            </div>
                        </div>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-300 dark:divide-zinc-700 dark:border-zinc-700" data-test="checkin-results">
                            @forelse ($results as $row)
                                <li wire:key="res-{{ $row['id'] }}">
                                    <button type="button" wire:click="select(@js($row['id']))" class="flex w-full items-center justify-between gap-3 px-4 py-4 text-start text-lg" data-test="checkin-result">
                                        <span>
                                            {{ $row['name'] }}
                                            @if ($row['nickname'])
                                                <span class="text-zinc-500">"{{ $row['nickname'] }}"</span>
                                            @endif
                                        </span>
                                        @if ($row['status'])
                                            <span class="rounded-full bg-zinc-200 px-3 py-1 text-sm font-medium text-zinc-900" data-test="result-status">
                                                {{ match ($row['status']) { 'playing' => __('Playing'), 'break' => __('On break'), default => __('Checked in') } }}
                                            </span>
                                        @endif
                                    </button>
                                </li>
                            @empty
                                <li class="px-4 py-4 text-zinc-600 dark:text-zinc-300">{{ __('No match. Try another spelling, or register below.') }}</li>
                            @endforelse
                        </ul>
                    @endif

                    <button type="button" wire:click="startRegister" class="w-full rounded-lg border border-zinc-400 px-4 py-3 text-lg font-medium" data-test="im-new">{{ __("I'm new") }}</button>
                </section>
            @else
                <form wire:submit="register" class="space-y-4" data-test="register-form">
                    <h2 class="text-xl font-bold">{{ __("I'm new") }}</h2>
                    @error('register')
                        <div role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3 text-red-900 dark:bg-red-950 dark:text-red-100" data-test="register-error">{{ $message }}</div>
                    @enderror

                    <div>
                        <label for="regName" class="block font-medium">{{ __('Full name') }}</label>
                        <input id="regName" type="text" wire:model="regName" maxlength="120" required autocomplete="off" class="mt-1 w-full rounded-lg border border-zinc-400 bg-white px-4 py-3 text-lg text-zinc-900" />
                        @error('name') <p class="text-red-600" data-test="error-name">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="regNickname" class="block font-medium">{{ __('Nickname') }}</label>
                        <input id="regNickname" type="text" wire:model="regNickname" maxlength="20" required autocomplete="off" class="mt-1 w-full rounded-lg border border-zinc-400 bg-white px-4 py-3 text-lg text-zinc-900" />
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Shown on the public queue and TV instead of your full name.') }}</p>
                        @error('nickname') <p class="text-red-600" data-test="error-nickname">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="regDuprId" class="block font-medium">{{ __('DUPR ID (optional)') }}</label>
                        <input id="regDuprId" type="text" wire:model="regDuprId" maxlength="6" autocomplete="off" class="mt-1 w-full rounded-lg border border-zinc-400 bg-white px-4 py-3 text-lg uppercase text-zinc-900" />
                        @error('dupr_id') <p class="text-red-600" data-test="error-dupr_id">{{ $message }}</p> @enderror
                    </div>

                    <fieldset>
                        <legend class="font-medium">{{ __('How would you rate your level?') }}</legend>
                        <div class="mt-2 grid gap-2" data-test="stars-options">
                            @foreach ($levels as $value => $description)
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg border-2 border-zinc-300 p-3 has-checked:border-green-600 has-checked:bg-green-50 has-checked:text-zinc-900 has-focus-visible:outline-2 dark:border-zinc-600">
                                    <input type="radio" wire:model="regStars" value="{{ $value }}" class="size-5" />
                                    <span class="text-xl font-bold">{{ $value }} <span aria-hidden="true">&#9733;</span></span>
                                    <span class="text-base">{{ __($description) }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('stars') <p class="text-red-600" data-test="error-stars">{{ $message }}</p> @enderror
                    </fieldset>

                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 rounded-lg bg-green-600 px-4 py-3 text-lg font-semibold text-white" data-test="register-submit">{{ __('Register and check in') }}</button>
                        <button type="button" wire:click="cancelRegister" class="rounded-lg border border-zinc-400 px-4 py-3 text-lg">{{ __('Back') }}</button>
                    </div>
                </form>
            @endif
        @endif
    @endif
</main>
