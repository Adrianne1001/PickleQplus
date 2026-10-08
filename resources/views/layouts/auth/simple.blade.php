<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">
        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden p-6 md:p-10">
            <div class="pointer-events-none absolute inset-x-0 top-0 -z-0 h-72 bg-linear-to-b from-brand-100/70 to-transparent dark:from-brand-950/60" aria-hidden="true"></div>

            <div class="absolute end-4 top-4 z-10 flex items-center gap-2 sm:end-6 sm:top-6">
                <x-theme-toggle />
            </div>

            <div class="relative z-10 flex w-full max-w-md flex-col gap-6">
                <x-brand class="self-center" size="md" wire:navigate />

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>

                <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
                    <a href="{{ route('home') }}" class="font-medium hover:text-zinc-900 dark:hover:text-white" wire:navigate>&larr; {{ __('Back to home') }}</a>
                </p>
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
