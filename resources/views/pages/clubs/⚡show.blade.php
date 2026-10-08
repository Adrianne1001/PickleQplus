<?php

use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
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

    /** Live sessions, newest first (several can be live when the club allows it). */
    #[Computed]
    public function liveSessions(): Collection
    {
        return $this->club->playSessions()->where('status', SessionStatus::Live->value)->latest('date')->latest('id')->get();
    }

    /** The most recent session that has started or ended (drafts are not "last"). */
    #[Computed]
    public function lastSession(): ?PlaySession
    {
        return $this->club->playSessions()->where('status', '!=', SessionStatus::Draft->value)->latest('date')->latest('id')->first();
    }

    #[Computed]
    public function sessionCount(): int
    {
        return $this->club->playSessions()->count();
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

<section class="w-full space-y-6 lg:space-y-8">
    <x-page-header :title="$club->name" :description="__('Run open play, manage your roster and see how your club is doing.')" :eyebrow="__('Club overview')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" class="min-h-11" :href="route('clubs.sessions.create', $club)" wire:navigate data-test="start-session-link">{{ __('New session') }}</flux:button>
        </x-slot:actions>
    </x-page-header>

    @php($live = $this->liveSessions)
    @php($last = $this->lastSession)

    @foreach ($live as $liveSession)
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border-2 border-brand-600/60 bg-brand-50 p-5 dark:border-brand-500/60 dark:bg-brand-950/40" data-test="live-session-card" wire:key="live-{{ $liveSession->id }}">
            <div class="min-w-0">
                <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-brand-800 dark:text-brand-300">
                    <span class="inline-block size-2 rounded-full bg-brand-600 dark:bg-brand-400" aria-hidden="true"></span>
                    {{ __('Live now') }}
                </p>
                <h2 class="mt-1 truncate text-xl font-bold text-zinc-900 dark:text-white">{{ $liveSession->name }}</h2>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $liveSession->date->format('D, j M Y') }} &middot; {{ trans_choice(':count court|:count courts', $liveSession->courts) }}</p>
            </div>
            <flux:button variant="primary" icon="play" class="min-h-12" :href="route('clubs.sessions.show', [$club, $liveSession])" wire:navigate data-test="open-board-link">{{ __('Open board') }}</flux:button>
        </section>
    @endforeach

    <x-card :title="__('Play sessions')" :description="__('Start an open play night, check players in and run the courts.')" data-test="sessions-placeholder">
        <x-slot:actions>
            <flux:button icon="calendar-days" :href="route('clubs.sessions.index', $club)" wire:navigate>{{ __('All sessions') }}</flux:button>
        </x-slot:actions>
        @if ($live->isEmpty() && $last)
            <div class="flex flex-wrap items-center justify-between gap-3" data-test="last-session">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">{{ __('Last session') }}</p>
                    <a href="{{ route('clubs.sessions.show', [$club, $last]) }}" wire:navigate class="font-semibold text-zinc-900 hover:underline dark:text-white">{{ $last->name }}</a>
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">&middot; {{ $last->date->format('D, j M Y') }}</span>
                </div>
                <flux:button variant="primary" icon="plus" :href="route('clubs.sessions.create', $club)" wire:navigate>{{ __('New session') }}</flux:button>
            </div>
        @elseif ($live->isEmpty())
            <x-empty-state icon="calendar-days" :title="__('No sessions yet')" :description="__('Start your first open play night.')" data-test="no-sessions">
                <flux:button variant="primary" icon="play" :href="route('clubs.sessions.create', $club)" wire:navigate>{{ __('Start a session') }}</flux:button>
            </x-empty-state>
        @else
            <flux:button icon="plus" :href="route('clubs.sessions.create', $club)" wire:navigate>{{ __('New session') }}</flux:button>
        @endif
    </x-card>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div data-test="stat-players">
            <x-stat-tile :label="__('Active players')" :value="$this->activePlayers" icon="user-group" tone="brand" />
        </div>
        <div data-test="stat-members">
            <x-stat-tile :label="__('Members')" :value="$this->memberCount" icon="users" />
        </div>
        <div data-test="stat-sessions">
            <x-stat-tile :label="__('Sessions')" :value="$this->sessionCount" icon="calendar-days" />
        </div>
    </div>

    <x-card :title="__('Quick links')">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <flux:button icon="user-group" class="min-h-12 justify-start" :href="route('clubs.players.index', $club)" wire:navigate>{{ __('Players') }}</flux:button>
            <flux:button icon="trophy" class="min-h-12 justify-start" :href="route('clubs.stats', $club)" wire:navigate>{{ __('Stats') }}</flux:button>
            <flux:button icon="users" class="min-h-12 justify-start" :href="route('clubs.members', $club)" wire:navigate>{{ __('Members') }}</flux:button>
            @if ($this->isOwner)
                <flux:button icon="cog-6-tooth" class="min-h-12 justify-start" :href="route('clubs.settings', $club)" wire:navigate>{{ __('Settings') }}</flux:button>
            @endif
        </div>
    </x-card>
</section>
