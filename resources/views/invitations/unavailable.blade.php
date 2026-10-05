<x-layouts::auth :title="__('Invitation unavailable')">
    <div class="flex flex-col gap-6 text-center" data-test="invitation-unavailable" data-reason="{{ $reason }}">
        @if ($reason === 'expired')
            <x-auth-header :title="__('This invitation has expired')" :description="__('Ask a club owner to send you a new one.')" />
        @elseif ($reason === 'used')
            <x-auth-header :title="__('This invitation was already used')" :description="__('If that was you, just log in to reach the club.')" />
        @else
            <x-auth-header :title="__('Invitation not found')" :description="__('The link is invalid or the invitation was withdrawn. Ask a club owner for a new one.')" />
        @endif

        <div class="flex flex-col gap-2">
            @if ($reason === 'used')
                <flux:button variant="primary" :href="route('login')" data-test="invitation-login">{{ __('Log in') }}</flux:button>
            @endif
            <flux:button :href="route('home')">{{ __('Back to home') }}</flux:button>
        </div>
    </div>
</x-layouts::auth>
