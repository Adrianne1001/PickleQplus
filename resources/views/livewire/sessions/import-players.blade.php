@php
    $statusBadge = [
        'existing' => ['green', __('Existing player')],
        'new' => ['blue', __('New player')],
        'listed' => ['zinc', __('Already on list')],
        'error' => ['red', __('Error')],
    ];
    $tabs = ['paste' => __('Paste list'), 'reclub' => __('Reclub link')];
    $summary = $this->summary;
    $parts = array_filter([
        $summary['new'] > 0 ? __(':count new', ['count' => $summary['new']]) : null,
        $summary['existing'] > 0 ? __(':count existing', ['count' => $summary['existing']]) : null,
        $summary['listed'] > 0 ? __(':count already listed', ['count' => $summary['listed']]) : null,
        $summary['error'] > 0 ? trans_choice(':count error|:count errors', $summary['error']) : null,
    ]);
@endphp

<div data-test="import-players">
    <flux:modal name="{{ \App\Livewire\Sessions\ImportPlayers::MODAL }}" class="w-full max-w-2xl" x-on:close="$wire.resetImport()">
        <div class="space-y-5" data-test="import-modal">
            <div>
                <flux:heading size="lg">{{ __('Import player list') }}</flux:heading>
                @if ($step === 'review')
                    <flux:text class="mt-1">{{ __('Review the players below. Fix a name, rating, DUPR ID or gender, or remove a row, before adding them.') }}</flux:text>
                @endif
            </div>

            @if ($step === 'input')
                {{-- Segmented tabs: Paste list / Reclub link. The active tab is kept in $tab so Back returns to it. --}}
                <div
                    role="tablist"
                    aria-label="{{ __('Import source') }}"
                    class="grid grid-cols-2 gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800"
                    x-on:keydown.arrow-right.prevent="$wire.tab = 'reclub'; $nextTick(() => $refs.tabReclub.focus())"
                    x-on:keydown.arrow-left.prevent="$wire.tab = 'paste'; $nextTick(() => $refs.tabPaste.focus())"
                >
                    @foreach ($tabs as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            id="import-tab-{{ $key }}"
                            x-ref="tab{{ ucfirst($key) }}"
                            aria-controls="import-panel-{{ $key }}"
                            x-on:click="$wire.tab = '{{ $key }}'"
                            x-bind:aria-selected="$wire.tab === '{{ $key }}'"
                            x-bind:tabindex="$wire.tab === '{{ $key }}' ? 0 : -1"
                            x-bind:class="$wire.tab === '{{ $key }}' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                            class="min-h-11 rounded-lg px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            data-test="import-tab-{{ $key }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>

                <div role="tabpanel" id="import-panel-paste" aria-labelledby="import-tab-paste" x-cloak x-show="$wire.tab === 'paste'" class="space-y-4" data-test="import-panel-paste">
                    <flux:text>{{ __('Copy from a group chat, notes, or Reclub. One player per line. Numbers are optional.') }}</flux:text>

                    <flux:textarea
                        wire:model="text"
                        rows="8"
                        :label="__('Player list')"
                        :placeholder="__('1. Ana / 2. Miguel / 3. Bea')"
                        autocomplete="off"
                        data-test="import-paste-input"
                    />
                    @error('paste')
                        <flux:callout variant="danger" icon="exclamation-circle" data-test="import-paste-error">
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        </flux:callout>
                    @enderror

                    <details class="group rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700" data-test="import-details-help">
                        <summary class="flex min-h-8 cursor-pointer list-none items-center justify-between gap-2 text-sm font-semibold text-zinc-900 focus-visible:outline-2 focus-visible:outline-brand-600 dark:text-white">
                            {{ __('Include ratings, DUPR IDs or gender') }}
                            <flux:icon.chevron-down class="size-4 shrink-0 transition-transform group-open:rotate-180" />
                        </summary>
                        <div class="mt-3 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <p>{{ __('Add details after each name. You can review and edit them before adding players.') }}</p>
                            <code class="block overflow-x-auto rounded-lg bg-zinc-100 px-3 py-2 font-mono text-sm text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">Ana | rating=4.2 | duprId=23Q432 | gender=woman</code>
                            <p>{{ __('Gender is optional: man or woman (m, w, male and female also work).') }}</p>
                        </div>
                    </details>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="filled" class="min-h-11" data-test="import-cancel">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button
                            type="button"
                            variant="primary"
                            class="min-h-11"
                            wire:click="reviewPaste"
                            wire:loading.attr="disabled"
                            wire:target="reviewPaste"
                            x-bind:disabled="! ($wire.text ?? '').trim()"
                            data-test="import-review-button"
                        >{{ __('Review players') }}</flux:button>
                    </div>
                </div>

                <div role="tabpanel" id="import-panel-reclub" aria-labelledby="import-tab-reclub" x-cloak x-show="$wire.tab === 'reclub'" class="space-y-4" data-test="import-panel-reclub">
                    <flux:text>{{ __('Paste your Reclub event link.') }}</flux:text>

                    <flux:input
                        wire:model="url"
                        type="url"
                        inputmode="url"
                        :label="__('Reclub event link')"
                        placeholder="https://reclub.co/m/YCFQJY"
                        autocomplete="off"
                        wire:keydown.enter.prevent="loadReclub"
                        data-test="import-reclub-input"
                    />
                    <flux:text size="sm">{{ __("You'll review the confirmed players before adding them.") }}</flux:text>
                    @error('reclub')
                        <flux:callout variant="danger" icon="exclamation-circle" data-test="import-reclub-error">
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        </flux:callout>
                    @enderror

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="filled" class="min-h-11" data-test="import-reclub-cancel">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button
                            type="button"
                            variant="primary"
                            class="min-h-11"
                            wire:click="loadReclub"
                            wire:loading.attr="disabled"
                            wire:target="loadReclub"
                            data-test="import-reclub-button"
                        >
                            <span wire:loading.remove wire:target="loadReclub">{{ __('Load players') }}</span>
                            <span wire:loading wire:target="loadReclub" data-test="import-reclub-loading">{{ __('Loading players…') }}</span>
                        </flux:button>
                    </div>
                </div>
            @else
                <div class="space-y-4" data-test="import-review">
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-800/60" role="status" data-test="import-summary">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $parts === [] ? __('No players found.') : implode(', ', $parts) }}</p>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ trans_choice(':count player will be added|:count players will be added', $summary['add']) }}</p>
                    </div>

                    @error('players')
                        <flux:callout variant="danger" icon="exclamation-circle" data-test="import-confirm-error">
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        </flux:callout>
                    @enderror
                    @error('session')
                        <flux:callout variant="danger" icon="exclamation-circle" data-test="import-confirm-error">
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        </flux:callout>
                    @enderror
                    @error('rows')
                        <flux:callout variant="danger" icon="exclamation-circle" data-test="import-confirm-error">
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        </flux:callout>
                    @enderror

                    <ul class="max-h-[45vh] divide-y divide-zinc-200 overflow-y-auto rounded-xl border border-zinc-200 px-4 dark:divide-zinc-700 dark:border-zinc-700" data-test="import-rows">
                        @foreach ($rows as $i => $row)
                            @php
                                $status = $row['status'] ?? 'error';
                                [$color, $label] = $statusBadge[$status] ?? $statusBadge['error'];
                            @endphp
                            <li class="space-y-3 py-4" wire:key="import-row-{{ $i }}" data-test="import-row" data-status="{{ $status }}">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="text-xs font-semibold tabular-nums text-zinc-600 dark:text-zinc-400">#{{ $i + 1 }}</span>
                                    <span data-test="import-row-status"><flux:badge size="sm" :color="$color">{{ $label }}</flux:badge></span>
                                    @if ($status === 'existing' && ($row['player_name'] ?? null) && $row['player_name'] !== ($row['name'] ?? ''))
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Matches :name', ['name' => $row['player_name']]) }}</span>
                                    @endif
                                    @if ($status !== 'existing' && $status !== 'new' && ($row['message'] ?? null))
                                        <span class="text-sm font-medium {{ $status === 'error' ? 'text-red-700 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-300' }}" data-test="import-row-message">{{ $row['message'] }}</span>
                                    @endif
                                </div>

                                @foreach ($row['warnings'] ?? [] as $warning)
                                    <p class="text-sm text-amber-800 dark:text-amber-300" data-test="import-row-warning">{{ $warning }}</p>
                                @endforeach

                                <div class="grid grid-cols-2 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_5rem_7rem_8rem_auto]">
                                    <div class="col-span-2 sm:col-span-1">
                                        <flux:input wire:model.blur="rows.{{ $i }}.name" size="sm" :label="__('Name')" maxlength="120" autocomplete="off" data-test="import-row-name" />
                                    </div>
                                    <flux:input wire:model.blur="rows.{{ $i }}.rating" size="sm" :label="__('Rating')" inputmode="decimal" placeholder="4.2" autocomplete="off" data-test="import-row-rating" />
                                    <flux:input wire:model.blur="rows.{{ $i }}.dupr_id" size="sm" :label="__('DUPR ID')" maxlength="6" class="uppercase" autocomplete="off" data-test="import-row-dupr" />
                                    {{-- An unrecognised pasted value is not one of the options, so the select shows blank until the user picks one. --}}
                                    <div class="col-span-2 sm:col-span-1">
                                        <flux:select wire:model.live="rows.{{ $i }}.gender" size="sm" :label="__('Gender')" data-test="import-row-gender">
                                            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                                            <flux:select.option value="man">{{ __('Man') }}</flux:select.option>
                                            <flux:select.option value="woman">{{ __('Woman') }}</flux:select.option>
                                        </flux:select>
                                    </div>
                                    <flux:button type="button" size="sm" variant="ghost" icon="x-mark" class="col-span-2 min-h-11 justify-self-end sm:col-span-1 sm:min-w-11" wire:click="dropRow({{ $i }})" aria-label="{{ __('Remove :name from the import', ['name' => ($row['name'] ?? '') !== '' ? $row['name'] : '#'.($i + 1)]) }}" data-test="import-row-drop">
                                        <span class="sm:sr-only">{{ __('Remove row') }}</span>
                                    </flux:button>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:button type="button" variant="ghost" icon="arrow-left" class="min-h-11" wire:click="back" data-test="import-back">{{ __('Back') }}</flux:button>
                        <div class="flex flex-wrap justify-end gap-2">
                            <flux:modal.close>
                                <flux:button type="button" variant="filled" class="min-h-11" data-test="import-review-cancel">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button
                                type="button"
                                variant="primary"
                                class="min-h-11"
                                wire:click="confirm"
                                wire:loading.attr="disabled"
                                wire:target="confirm"
                                :disabled="$summary['add'] === 0"
                                data-test="import-confirm-button"
                            >{{ trans_choice('Add :count player|Add :count players', $summary['add']) }}</flux:button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
