@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'PickleQ+')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-brand-700 shadow-xs">
            <x-app-logo-icon class="size-5 text-ball" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'PickleQ+')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-brand-700 shadow-xs">
            <x-app-logo-icon class="size-5 text-ball" />
        </x-slot>
    </flux:brand>
@endif
