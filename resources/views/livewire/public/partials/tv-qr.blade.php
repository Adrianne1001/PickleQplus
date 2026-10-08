<div class="flex items-center gap-5 {{ $size === 'large' ? 'flex-col' : 'rounded-2xl border border-brand-700 bg-brand-950/70 p-4' }}" data-test="tv-qr">
    <div class="shrink-0 rounded-2xl bg-white p-3 shadow-lg [&>svg]:block [&>svg]:size-full {{ $size === 'large' ? 'size-[28rem]' : 'size-52' }}">{!! $svg !!}</div>
    <div class="{{ $size === 'large' ? 'text-center' : '' }}">
        <p class="{{ $size === 'large' ? 'text-6xl' : 'text-3xl' }} font-extrabold text-white">{{ __('Scan to check in') }}</p>
        <p class="{{ $size === 'large' ? 'text-3xl' : 'text-sm' }} mt-2 break-all text-brand-300" data-test="tv-checkin-url">{{ preg_replace('#^https?://#', '', $checkinUrl) }}</p>
    </div>
</div>
