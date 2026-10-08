<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Verify your email')" :description="__('One quick step before you start.')" />
        <flux:text class="text-center">
            {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium !text-brand-700 dark:!text-brand-400">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </flux:text>
        @endif

        <div class="flex flex-col items-stretch gap-3">
            <form method="POST" action="{{ route('verification.send') }}" class="contents">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Resend verification email') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="contents">
                @csrf
                <flux:button variant="ghost" type="submit" class="w-full cursor-pointer text-sm" data-test="logout-button">
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
