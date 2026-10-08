@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-1 text-center">
    <flux:heading size="xl" level="1" class="font-bold tracking-tight">{{ $title }}</flux:heading>
    <flux:subheading class="text-zinc-600 dark:text-zinc-400">{{ $description }}</flux:subheading>
</div>
