<x-layouts::public :title="__('Check-in closed')">
    <main class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center gap-6 px-6 text-center" data-test="checkin-closed">
        <x-brand size="md" />
        <x-empty-state icon="lock-closed" :title="__('Check-in is closed')" :description="__('This check-in link is no longer active. Please ask the organizer for the current QR code.')" class="w-full bg-white dark:bg-zinc-900" />
    </main>
</x-layouts::public>
