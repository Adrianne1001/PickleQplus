@props(['dark' => false])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $dark])>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex, nofollow" />
        <title>{{ filled($title ?? null) ? $title.' - '.config('app.name', 'PickleQ+') : config('app.name', 'PickleQ+') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @stack('head')
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- The forced-dark TV page skips the theme script; every other public page follows the shared choice (light by default). --}}
        @unless ($dark)
            @include('partials.appearance')
        @else
            <style>:root.dark { color-scheme: dark; }</style>
        @endunless
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        {{ $slot }}

        @unless ($dark)
            <div class="pointer-events-none fixed bottom-4 end-4 z-40 print:hidden">
                <x-theme-toggle class="pointer-events-auto border border-zinc-200 bg-white shadow-md dark:border-zinc-700 dark:bg-zinc-900" />
            </div>
        @endunless
    </body>
</html>
