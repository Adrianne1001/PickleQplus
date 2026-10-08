@props(['shareUrl', 'shareSvg', 'title', 'text', 'gifUrl' => null, 'pngUrl' => null])

{{--
    Share panel for an ended session: link, native share sheet, QR, social links and the podium
    GIF / image downloads. Plain links only (no SDKs). gifUrl / pngUrl are null when there is no podium.
--}}
@php
    $enc = fn (string $v): string => rawurlencode($v);
    $withUrl = $text.' '.$shareUrl;
    $links = [
        ['key' => 'facebook', 'label' => 'Facebook', 'href' => 'https://www.facebook.com/sharer/sharer.php?u='.$enc($shareUrl), 'mobile' => false],
        ['key' => 'x', 'label' => 'X', 'href' => 'https://x.com/intent/post?text='.$enc($text).'&url='.$enc($shareUrl), 'mobile' => false],
        ['key' => 'whatsapp', 'label' => 'WhatsApp', 'href' => 'https://wa.me/?text='.$enc($withUrl), 'mobile' => false],
        ['key' => 'messenger', 'label' => 'Messenger', 'href' => 'fb-messenger://share?link='.$enc($shareUrl), 'mobile' => true],
    ];
    $btn = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800';
    $btnPrimary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
@endphp

<section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-700 dark:bg-zinc-900 sm:p-6"
    x-data="{ canShare: typeof navigator.share === 'function', mobile: /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) }"
    aria-labelledby="share-h" data-test="share-panel">
    <h2 id="share-h" class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Share these results') }}</h2>
    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Anyone with the link can see them. No login needed.') }}</p>

    <div class="mt-5 grid gap-6 md:grid-cols-[minmax(0,1fr)_auto]">
        <div class="space-y-4">
            <x-copy-field :label="__('Results link')" :value="$shareUrl" test="results-share-url" />

            <div class="flex flex-wrap gap-2">
                <button type="button" x-show="canShare" x-cloak class="{{ $btnPrimary }}"
                    x-on:click="navigator.share({ title: @js($title), text: @js($text), url: @js($shareUrl) }).catch(() => {})" data-test="native-share">{{ __('Share…') }}</button>
                @foreach ($links as $l)
                    <a href="{{ $l['href'] }}" @unless ($l['mobile']) target="_blank" rel="noopener noreferrer" @endunless
                        @if ($l['mobile']) x-show="mobile" x-cloak @endif
                        class="{{ $btn }}" data-test="share-{{ $l['key'] }}">{{ $l['label'] }}</a>
                @endforeach
            </div>

            @if ($gifUrl && $pngUrl)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ $gifUrl }}{{ str_contains($gifUrl, '?') ? '&' : '?' }}download=1" download class="{{ $btnPrimary }}" data-test="download-gif">
                        <flux:icon.arrow-down-tray class="size-4" />{{ __('Download GIF') }}
                    </a>
                    <a href="{{ $pngUrl }}{{ str_contains($pngUrl, '?') ? '&' : '?' }}download=1" download class="{{ $btn }}" data-test="download-image">
                        <flux:icon.photo class="size-4" />{{ __('Download image') }}
                    </a>
                </div>
            @endif
        </div>

        <div class="mx-auto w-full max-w-44 text-center md:mx-0">
            <div class="rounded-xl bg-white p-2 text-zinc-900 shadow-sm ring-1 ring-zinc-200 [&>svg]:h-auto [&>svg]:w-full" data-test="results-share-qr" role="img" aria-label="{{ __('QR code for the results link') }}">{!! $shareSvg !!}</div>
            <p class="mt-2 text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ __('Scan to open on a phone') }}</p>
        </div>
    </div>

    @if ($gifUrl)
        <div class="mt-6 border-t border-zinc-200 pt-5 dark:border-zinc-700">
            <p class="mb-3 text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">{{ __('Podium GIF preview') }}</p>
            <img src="{{ $gifUrl }}" alt="{{ __('Animated podium of the top three players') }}" width="400" height="400" loading="lazy"
                class="mx-auto aspect-square w-full max-w-sm rounded-2xl bg-zinc-100 shadow-md ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700" data-test="gif-preview" />
        </div>
    @endif
</section>
