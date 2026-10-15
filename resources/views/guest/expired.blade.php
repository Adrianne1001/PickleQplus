<x-layouts::auth :title="__('Guest session expired')">
    <div class="flex flex-col items-center gap-6 text-center" data-test="guest-expired">
        <span class="flex size-14 items-center justify-center rounded-full bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300">
            <flux:icon.clock class="size-7" />
        </span>

        <div class="space-y-2">
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('This guest session has ended') }}</h1>
            <p class="text-zinc-700 dark:text-zinc-300" data-test="guest-expired-message">{{ __('This guest session has ended and its data was deleted.') }}</p>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Guest sessions expire :idle h after the last activity, or :max h after they were started. Start a new one any time. Sign up if you want to keep your sessions next time.', ['idle' => config('pickleq.guest.idle_hours'), 'max' => config('pickleq.guest.max_hours')]) }}</p>
        </div>

        <div class="flex w-full flex-col gap-2">
            @guest
                <form method="POST" action="{{ route('guest.start') }}">
                    @csrf
                    <flux:button type="submit" variant="primary" class="w-full min-h-11" data-test="expired-start-guest">{{ __('Start a new guest session') }}</flux:button>
                </form>
                @if (Route::has('register'))
                    <flux:button :href="route('register')" class="w-full min-h-11" data-test="expired-register">{{ __('Sign up for free') }}</flux:button>
                @endif
            @else
                <flux:button variant="primary" :href="route('dashboard')" class="w-full min-h-11" data-test="expired-dashboard">{{ __('Go to dashboard') }}</flux:button>
            @endguest
        </div>

        <a href="{{ route('home') }}" class="text-sm font-semibold text-brand-700 underline underline-offset-2 dark:text-brand-400" data-test="expired-home">{{ __('Back to PickleQ+') }}</a>
    </div>
</x-layouts::auth>
