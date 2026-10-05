<x-layouts::auth :title="__('Club invitation')">
    <div class="flex flex-col gap-6 text-center" data-test="invitation-show">
        @php
            $description = $existingRole
                ? __("You're already a member of :club (:role). Accepting won't change your role.", ['club' => $club->name, 'role' => $existingRole->value])
                : __('You have been invited to join as :role.', ['role' => $invitation->role->value]);
        @endphp
        <x-auth-header :title="__('Join :club', ['club' => $club->name])" :description="$description" />

        <div class="flex justify-center">
            <flux:badge size="lg" color="blue" icon="user-group">{{ $club->name }} · {{ ucfirst(($existingRole ?? $invitation->role)->value) }}</flux:badge>
        </div>

        @error('invitation')
            <flux:callout variant="danger" icon="exclamation-triangle" data-test="invitation-error">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror

        <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}">
            @csrf
            <flux:button type="submit" variant="primary" class="w-full" data-test="invitation-accept">{{ __('Accept invitation') }}</flux:button>
        </form>
    </div>
</x-layouts::auth>
