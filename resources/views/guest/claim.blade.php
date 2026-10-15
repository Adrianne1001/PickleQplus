<x-layouts::auth :title="__('Keep this session')">
    <div class="flex flex-col gap-6" data-test="guest-claim">
        <x-auth-header :title="__('Keep this session')" :description="__('Turn this guest session into a club you own, so it is never deleted.')" />

        @error('claim')
            <flux:callout variant="danger" icon="exclamation-triangle" data-test="claim-error">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror

        <ul class="space-y-2 rounded-xl bg-zinc-50 p-4 text-sm text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300" data-test="claim-explainer">
            <li class="flex gap-2"><flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-brand-700 dark:text-brand-400" /><span>{{ __('You become the owner of a new club. Your players, check-ins, matches and results are kept.') }}</span></li>
            <li class="flex gap-2"><flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-brand-700 dark:text-brand-400" /><span>{{ __('The queue, TV and check-in links and QR codes keep working.') }}</span></li>
            <li class="flex gap-2"><flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-brand-700 dark:text-brand-400" /><span>{{ __('Guest limits and the deletion timer are removed, and you can use stats, the player list and DUPR export.') }}</span></li>
            <li class="flex gap-2"><flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-700 dark:text-amber-400" /><span>{{ __('The organizer link stops working. From now on you run the session by logging in.') }}</span></li>
        </ul>

        <form method="POST" action="{{ route('guest.claim.store', $club) }}" class="space-y-5">
            @csrf
            <flux:input
                name="name"
                :label="__('Club name (optional)')"
                :value="old('name', $session?->name)"
                maxlength="120"
                :placeholder="__('Your club')"
                data-test="claim-name"
            />

            <flux:button type="submit" variant="primary" class="w-full min-h-11" data-test="claim-submit">{{ __('Keep this session') }}</flux:button>
        </form>

        @if ($session)
            <a href="{{ route('clubs.sessions.show', [$club, $session]) }}" class="text-center text-sm font-semibold text-brand-700 underline underline-offset-2 dark:text-brand-400" data-test="claim-back">{{ __('Back to the session') }}</a>
        @endif
    </div>
</x-layouts::auth>
