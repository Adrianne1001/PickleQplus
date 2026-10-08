@props([
    'status',
])

@if ($status)
    <div role="status" {{ $attributes->merge(['class' => 'rounded-xl bg-brand-50 px-4 py-3 text-sm font-medium text-brand-800 dark:bg-brand-950 dark:text-brand-300']) }}>
        {{ $status }}
    </div>
@endif
