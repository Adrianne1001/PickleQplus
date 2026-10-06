<div class="flex items-center gap-4 {{ $size === 'large' ? 'flex-col' : '' }}" data-test="tv-qr">
    <div class="shrink-0 rounded-lg bg-white p-2 [&>svg]:block [&>svg]:size-full {{ $size === 'large' ? 'size-96' : 'size-40' }}">{!! $svg !!}</div>
    <div class="{{ $size === 'large' ? 'text-center' : '' }}">
        <p class="{{ $size === 'large' ? 'text-5xl' : 'text-2xl' }} font-bold">{{ __('Scan to check in') }}</p>
        <p class="{{ $size === 'large' ? 'text-2xl' : 'text-xs' }} mt-1 break-all text-zinc-400" data-test="tv-checkin-url">{{ preg_replace('#^https?://#', '', $checkinUrl) }}</p>
    </div>
</div>
