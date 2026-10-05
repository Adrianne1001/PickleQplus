<x-layouts::auth :title="__('Club invitation')">
    <div class="flex flex-col gap-6 text-center" data-test="invitation-wrong-account">
        <x-auth-header :title="__('Wrong account')" :description="__('This invitation to :club was sent to a different address.', ['club' => $club->name])" />

        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>
                {{ __('The invitation was sent to :email, but you are signed in as :current. Log out and sign in with the invited address to accept it.', ['email' => $maskedEmail, 'current' => $currentEmail]) }}
            </flux:callout.text>
        </flux:callout>

        <form method="POST" action="{{ route('logout') }}" class="flex flex-col gap-2">
            @csrf
            <flux:button type="submit" variant="primary" class="w-full" icon="arrow-right-start-on-rectangle" data-test="invitation-logout">{{ __('Log out and switch account') }}</flux:button>
            <flux:button :href="route('dashboard')" variant="ghost" data-test="invitation-dashboard">{{ __('Stay signed in') }}</flux:button>
        </form>
    </div>
</x-layouts::auth>
