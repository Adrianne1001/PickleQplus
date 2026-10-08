@php
    $tabs = [
        'checkin' => ['label' => __('Check-in'), 'title' => __('Check-in QR'), 'url' => $checkinUrl, 'svg' => $svg, 'blurb' => __('Players scan this to check themselves in.'), 'field' => __('Check-in link'), 'urlTest' => 'checkin-url', 'qrTest' => 'checkin-qr-svg'],
        'queue' => ['label' => __('Public queue'), 'title' => __('Public queue QR'), 'url' => $queueUrl, 'svg' => $queueSvg, 'blurb' => __('Players scan this to follow the queue and see when they\'re up.'), 'field' => __('Public queue link'), 'urlTest' => 'queue-url', 'qrTest' => 'queue-qr-svg'],
        'tv' => ['label' => __('TV display'), 'title' => __('TV display QR'), 'url' => $tvUrl, 'svg' => $tvSvg, 'blurb' => __('Open this on the venue TV. Keep this link private.'), 'field' => __('TV link'), 'urlTest' => 'tv-url', 'qrTest' => 'tv-qr-svg'],
    ];
@endphp
<section id="share" x-data="{ open: false, tab: 'checkin', fs: null, fsTrigger: null,
        closeFs() { this.fs = null; this.$nextTick(() => this.fsTrigger?.focus()) }, order: ['checkin', 'queue', 'tv'],
        move(e, dir) { const i = (this.order.indexOf(this.tab) + dir + this.order.length) % this.order.length; this.tab = this.order[i]; this.$nextTick(() => this.$refs['tab-' + this.tab].focus()) } }"
    x-on:open-share.window="open = true" x-on:keydown.escape.window="fs && closeFs()"
    class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900" data-test="checkin-qr-panel">
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Share: QR codes and links') }}</h2>
            <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400">{{ __('QR codes and links for check-in, the public queue and the TV.') }}</p>
        </div>
        <flux:button type="button" size="sm" class="min-h-10" icon="qr-code" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="share-body">
            <span x-show="!open">{{ __('Show') }}</span>
            <span x-show="open" x-cloak>{{ __('Hide') }}</span>
        </flux:button>
    </div>

    <div id="share-body" x-show="open" x-cloak class="border-t border-zinc-100 dark:border-zinc-800">
        <div role="tablist" aria-label="{{ __('Share') }}" class="flex gap-1 overflow-x-auto border-b border-zinc-200 px-3 pt-2 dark:border-zinc-700"
            x-on:keydown.arrow-right.prevent="move($event, 1)" x-on:keydown.arrow-left.prevent="move($event, -1)"
            x-on:keydown.home.prevent="tab = order[0]; $nextTick(() => $refs['tab-' + tab].focus())" x-on:keydown.end.prevent="tab = order[order.length - 1]; $nextTick(() => $refs['tab-' + tab].focus())">
            @foreach ($tabs as $key => $t)
                <button type="button" role="tab" id="share-tab-{{ $key }}" x-ref="tab-{{ $key }}" aria-controls="share-panel-{{ $key }}"
                    x-on:click="tab = '{{ $key }}'" x-bind:aria-selected="tab === '{{ $key }}'" x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                    x-bind:class="tab === '{{ $key }}' ? 'border-brand-700 text-brand-800 dark:border-brand-400 dark:text-brand-300' : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                    class="min-h-11 shrink-0 whitespace-nowrap border-b-2 px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-600"
                    data-test="share-tab-{{ $key }}">{{ $t['label'] }}</button>
            @endforeach
        </div>

        @foreach ($tabs as $key => $t)
            <div role="tabpanel" id="share-panel-{{ $key }}" aria-labelledby="share-tab-{{ $key }}" x-show="tab === '{{ $key }}'" @if ($key !== 'checkin') x-cloak @endif class="p-5" data-test="share-panel-{{ $key }}">
                @if ($key === 'checkin' && ! $t['svg'])
                    <flux:subheading data-test="checkin-qr-ended">{{ __('This session has ended, so check-in is closed.') }}</flux:subheading>
                @elseif ($key === 'tv' && ! $t['svg'])
                    <flux:subheading data-test="tv-qr-ended">{{ __('This session has ended, so the TV display is closed.') }}</flux:subheading>
                @else
                    <p class="mb-4 text-sm text-zinc-700 dark:text-zinc-300">{{ $t['blurb'] }}</p>
                    <div class="flex flex-wrap items-start gap-6">
                        <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200" data-test="{{ $t['qrTest'] }}" aria-label="{{ $t['title'] }}">
                            <div class="size-64 max-w-full text-zinc-900 [&>svg]:size-full" x-ref="qr-{{ $key }}">{!! $t['svg'] !!}</div>
                        </div>

                        <div class="min-w-64 flex-1 space-y-4">
                            <x-copy-field :label="$t['field']" :value="$t['url']" :test="$t['urlTest']" />

                            <div class="flex flex-wrap gap-2">
                                <flux:button as="a" href="{{ $t['url'] }}" target="_blank" rel="noopener" icon="arrow-top-right-on-square" class="min-h-11" data-test="open-{{ $key }}">{{ __('Open') }}</flux:button>
                                @if ($key !== 'tv')
                                    <flux:button type="button" icon="arrows-pointing-out" class="min-h-11"
                                        x-on:click="fsTrigger = $el; fs = { title: @js($t['title']), url: @js($t['url']), html: $refs['qr-{{ $key }}'].innerHTML }" data-test="fullscreen-{{ $key }}">{{ __('Full screen') }}</flux:button>
                                @endif
                            </div>

                            @if ($key === 'checkin')
                                <flux:modal.trigger name="regenerate-qr">
                                    <flux:button size="sm" variant="subtle" icon="arrow-path" data-test="regenerate-qr-button">{{ __('Regenerate QR') }}</flux:button>
                                </flux:modal.trigger>
                            @elseif ($key === 'tv')
                                <flux:button size="sm" variant="danger" icon="arrow-path" wire:click="resetTvLink" wire:confirm="{{ __('Reset the TV link? Any TV using the old link stops updating; open the new link on the TV.') }}" data-test="reset-tv-link-button">{{ __('Reset TV link') }}</flux:button>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Full-screen QR for scanning from a tablet --}}
    <div x-show="fs" x-cloak x-trap.noscroll="fs !== null" x-transition.opacity class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-white p-6 text-center dark:bg-zinc-950" role="dialog" aria-modal="true" aria-label="{{ __('Full screen QR') }}" data-test="share-fullscreen">
        <p class="text-2xl font-bold text-zinc-900 dark:text-white" x-text="fs?.title"></p>
        <div class="aspect-square w-[min(80vw,70vh)] rounded-2xl bg-white p-4 text-zinc-900 shadow ring-1 ring-zinc-200 [&>svg]:size-full" x-html="fs?.html"></div>
        <p class="max-w-full break-all text-lg text-zinc-700 dark:text-zinc-300" x-text="fs?.url"></p>
        <flux:button type="button" variant="filled" class="min-h-11" x-on:click="closeFs()" data-test="share-fullscreen-close">{{ __('Close') }}</flux:button>
    </div>

    @if ($svg)
        <flux:modal name="regenerate-qr" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Regenerate the check-in QR?') }}</flux:heading>
                    <flux:subheading>{{ __('The old QR stops working. Printed or shared copies will no longer check anyone in.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="regenerate" data-test="confirm-regenerate-qr-button">{{ __('Regenerate') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
