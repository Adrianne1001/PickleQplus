{{--
    Club switcher + club navigation for the sidebar.
    The current club is the one resolved by the club.member middleware, else the
    user's last visited club. With no current club the club nav is hidden.
--}}
@php
    use App\Enums\ClubRole;
    use App\Models\Club;

    $user = auth()->user();
    $userClubs = $user->switcherClubs();
    $current = Club::current();
    $current = $current !== null
        ? $userClubs->firstWhere('id', $current->id)
        : $userClubs->firstWhere('id', $user->current_club_id);
    $isOwner = $current !== null && $current->pivot->role === ClubRole::Owner;
@endphp

<flux:sidebar.nav>
    <flux:dropdown position="bottom" align="start" class="w-full">
        <flux:button
            variant="subtle"
            class="w-full justify-between"
            icon-trailing="chevrons-up-down"
            data-test="club-switcher"
        >
            <span class="truncate">{{ $current?->name ?? __('Select a club') }}</span>
        </flux:button>

        <flux:menu class="min-w-64">
            @if ($userClubs->isNotEmpty())
                <flux:menu.group :heading="__('Your clubs')">
                    @foreach ($userClubs as $club)
                        <flux:menu.item
                            :href="route('clubs.show', $club)"
                            :icon="$current?->id === $club->id ? 'check' : null"
                            wire:navigate
                            data-test="club-switcher-item"
                        >
                            <span class="flex w-full items-center justify-between gap-3">
                                <span class="truncate">{{ $club->name }}</span>
                                <flux:badge size="sm" :color="$club->pivot->role === ClubRole::Owner ? 'amber' : 'zinc'">
                                    {{ __(ucfirst($club->pivot->role->value)) }}
                                </flux:badge>
                            </span>
                        </flux:menu.item>
                    @endforeach
                </flux:menu.group>

                <flux:menu.separator />
            @endif

            <flux:menu.item icon="plus" :href="route('clubs.create')" wire:navigate data-test="club-switcher-create">
                {{ __('Create club') }}
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</flux:sidebar.nav>

@if ($current !== null)
    <flux:sidebar.nav>
        <flux:sidebar.group :heading="__('Club')" class="grid" data-test="club-nav">
            <flux:sidebar.item icon="home" :href="route('clubs.show', $current)" :current="request()->routeIs('clubs.show')" wire:navigate>
                {{ __('Overview') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="user-group" :href="route('clubs.players.index', $current)" :current="request()->routeIs('clubs.players.*')" wire:navigate>
                {{ __('Players') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="users" :href="route('clubs.members', $current)" :current="request()->routeIs('clubs.members*')" wire:navigate>
                {{ __('Members') }}
            </flux:sidebar.item>
            @if ($isOwner)
                <flux:sidebar.item icon="cog-6-tooth" :href="route('clubs.settings', $current)" :current="request()->routeIs('clubs.settings')" wire:navigate>
                    {{ __('Settings') }}
                </flux:sidebar.item>
            @endif
        </flux:sidebar.group>
    </flux:sidebar.nav>
@endif
