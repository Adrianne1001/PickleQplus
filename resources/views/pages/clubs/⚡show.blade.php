<?php

use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Club overview')] class extends Component {
    #[Locked]
    public Club $club;

    public function mount(Club $club): void
    {
        $this->authorize('view', $club);

        $this->club = $club;
    }

    #[Computed]
    public function activePlayers(): int
    {
        return $this->club->players()->where('active', true)->count();
    }

    #[Computed]
    public function memberCount(): int
    {
        return $this->club->users()->count();
    }

    #[Computed]
    public function isOwner(): bool
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->isOwnerOf($this->club);
    }
}; ?>

<section class="w-full space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ $club->name }}</flux:heading>
        <flux:subheading>{{ __('Club overview') }}</flux:subheading>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:card data-test="stat-players">
            <flux:subheading>{{ __('Active players') }}</flux:subheading>
            <flux:heading size="xl" class="mt-1">{{ $this->activePlayers }}</flux:heading>
        </flux:card>
        <flux:card data-test="stat-members">
            <flux:subheading>{{ __('Members') }}</flux:subheading>
            <flux:heading size="xl" class="mt-1">{{ $this->memberCount }}</flux:heading>
        </flux:card>
    </div>

    {{-- PLACEHOLDER (Phase 2, P2.1): live and upcoming play sessions go here. --}}
    <flux:card class="border-dashed" data-test="sessions-placeholder">
        <flux:heading>{{ __('Play sessions') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Starting and running open-play sessions is coming soon. Set up your roster in the meantime.') }}
        </flux:text>
    </flux:card>

    <div>
        <flux:heading class="mb-3">{{ __('Quick links') }}</flux:heading>
        <div class="flex flex-wrap gap-3">
            <flux:button icon="user-group" :href="route('clubs.players.index', $club)" wire:navigate>{{ __('Players') }}</flux:button>
            <flux:button icon="users" :href="route('clubs.members', $club)" wire:navigate>{{ __('Members') }}</flux:button>
            @if ($this->isOwner)
                <flux:button icon="cog-6-tooth" :href="route('clubs.settings', $club)" wire:navigate>{{ __('Settings') }}</flux:button>
            @endif
        </div>
    </div>
</section>
