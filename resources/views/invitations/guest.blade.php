<x-layouts::auth :title="__('Club invitation')">
    <div class="flex flex-col gap-6 text-center" data-test="invitation-guest">
        <x-auth-header :title="__('Join :club', ['club' => $club->name])" :description="__('You have been invited to join as :role.', ['role' => $invitation->role->value])" />

        <flux:callout icon="envelope" data-test="invitation-masked-email">
            <flux:callout.text>
                {{ __('This invitation was sent to :email. Log in or create an account with that address to accept it.', ['email' => $maskedEmail]) }}
            </flux:callout.text>
        </flux:callout>

        <div class="flex flex-col gap-2">
            <flux:button variant="primary" :href="route('login')" data-test="invitation-login">{{ __('Log in') }}</flux:button>
            <flux:button :href="route('register')" data-test="invitation-register">{{ __('Create an account') }}</flux:button>
        </div>
    </div>
</x-layouts::auth>
