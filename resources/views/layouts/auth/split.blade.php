<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-950">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col bg-brand-900 p-10 text-white lg:flex">
                <a href="{{ route('home') }}" class="relative z-20 flex items-center gap-2.5 text-lg font-bold" wire:navigate>
                    <span class="flex size-10 items-center justify-center rounded-xl bg-brand-700">
                        <x-app-logo-icon class="size-6 text-ball" />
                    </span>
                    {{ config('app.name', 'PickleQ+') }}
                </a>

                <div class="relative z-20 mt-auto">
                    <p class="text-2xl font-bold leading-snug">{{ __('Fair games. Short waits. More dinking.') }}</p>
                    <p class="mt-2 text-brand-100">{{ __('Open-play queue and court rotation for your club.') }}</p>
                </div>
            </div>
            <div class="relative w-full lg:p-8">
                <div class="absolute end-4 top-4 sm:end-6 sm:top-6">
                    <x-theme-toggle />
                </div>
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <x-brand class="self-center lg:hidden" size="md" wire:navigate />
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
