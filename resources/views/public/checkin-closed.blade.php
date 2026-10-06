<x-layouts::public :title="__('Check-in closed')">
    <main class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center gap-3 px-6 text-center" data-test="checkin-closed">
        <h1 class="text-3xl font-bold">{{ __('Check-in is closed') }}</h1>
        <p class="text-lg text-zinc-600 dark:text-zinc-300">{{ __('This check-in link is no longer active. Please ask the organizer for the current QR code.') }}</p>
    </main>
</x-layouts::public>
