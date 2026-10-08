<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">
        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="absolute end-4 top-4 z-10 sm:end-6 sm:top-6">
                <x-theme-toggle />
            </div>

            <div class="flex w-full max-w-md flex-col gap-6">
                <x-brand class="self-center" size="md" wire:navigate />

                <div class="rounded-2xl border border-zinc-200 bg-white px-8 py-8 shadow-sm sm:px-10 dark:border-zinc-800 dark:bg-zinc-900">
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
