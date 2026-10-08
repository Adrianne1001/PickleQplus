<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\User;
use App\Services\ClubService;
use App\Services\InvitationService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Club members')] class extends Component {
    #[Locked]
    public Club $club;

    public string $inviteEmail = '';

    public string $inviteRole = 'staff';

    /** Bumped when a role change is refused, so the dropdown re-renders at the real role. */
    #[Locked]
    public int $roleResync = 0;

    public function mount(Club $club): void
    {
        $this->authorize('view', $club);

        $this->club = $club;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->club->users()->orderBy('name')->get();
    }

    #[Computed]
    public function isOwner(): bool
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->isOwnerOf($this->club);
    }

    public function changeRole(int $userId, string $role, ClubService $clubs): void
    {
        $this->authorize('manageMembers', $this->club);

        $newRole = ClubRole::tryFrom($role);
        if ($newRole === null) {
            throw ValidationException::withMessages(['member' => __('Choose a valid role.')]);
        }

        $member = $this->club->users()->findOrFail($userId);

        try {
            $clubs->changeRole($this->club, $member, $newRole);
        } catch (ValidationException $e) {
            $this->roleResync++;

            throw $e;
        }

        unset($this->members);
        Flux::toast(variant: 'success', text: __(':name is now :role.', ['name' => $member->name, 'role' => $newRole->value]));
    }

    /**
     * Owners remove anyone; any member may remove themselves (leave).
     */
    public function removeMember(int $userId, ClubService $clubs): void
    {
        /** @var User $actor */
        $actor = Auth::user();

        $member = $this->club->users()->findOrFail($userId);
        $leaving = $actor->is($member);

        $this->authorize($leaving ? 'leave' : 'manageMembers', $this->club);

        $clubs->removeMember($this->club, $member);

        if ($leaving) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        unset($this->members);
        Flux::toast(variant: 'success', text: __(':name was removed.', ['name' => $member->name]));
    }

    /**
     * @return Collection<int, ClubInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return $this->club->pendingInvitations()->with('inviter')->get();
    }

    public function invite(InvitationService $invitations): void
    {
        $this->authorize('manageMembers', $this->club);

        $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255'],
            'inviteRole' => ['required', Rule::enum(ClubRole::class)],
        ], attributes: ['inviteEmail' => __('email'), 'inviteRole' => __('role')]);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $invitation = $invitations->invite($this->club, $actor, $this->inviteEmail, ClubRole::from($this->inviteRole));
        } catch (ValidationException $e) {
            $this->addError('inviteEmail', $this->firstMessage($e));

            return;
        }

        $this->reset('inviteEmail');
        $this->inviteRole = ClubRole::Staff->value;
        $this->resetErrorBag();
        unset($this->invitations);
        Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $invitation->email]));
    }

    public function resendInvitation(int $invitationId, InvitationService $invitations): void
    {
        $this->authorize('manageMembers', $this->club);

        $invitation = $this->club->pendingInvitations()->findOrFail($invitationId);

        try {
            $invitations->resend($invitation);
        } catch (ValidationException $e) {
            $this->addError('invitation', $this->firstMessage($e));

            return;
        }

        $this->resetErrorBag();
        unset($this->invitations);
        Flux::toast(variant: 'success', text: __('Invitation resent to :email.', ['email' => $invitation->email]));
    }

    public function revokeInvitation(int $invitationId, InvitationService $invitations): void
    {
        $this->authorize('manageMembers', $this->club);

        $invitation = $this->club->pendingInvitations()->findOrFail($invitationId);

        $invitations->revoke($invitation);

        unset($this->invitations);
        Flux::toast(variant: 'success', text: __('Invitation to :email revoked.', ['email' => $invitation->email]));
    }

    private function firstMessage(ValidationException $e): string
    {
        return (string) collect($e->errors())->flatten()->first();
    }
}; ?>

<section class="w-full max-w-4xl space-y-6 lg:space-y-8">
    <x-page-header :eyebrow="$club->name" :title="__('Members')" :description="__('Owners manage settings and members. Staff manage players and run sessions.')" />

    @error('member')
        <flux:callout variant="danger" icon="exclamation-triangle" data-test="member-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <x-card :title="__('Club members')" :padding="false">
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700" data-test="member-list">
            @foreach ($this->members as $member)
                @php($isSelf = $member->is(auth()->user()))
                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4" wire:key="member-{{ $member->id }}">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:avatar :name="$member->name" :initials="$member->initials()" />
                        <div class="min-w-0">
                            <flux:heading class="truncate">
                                {{ $member->name }}
                                @if ($isSelf)
                                    <span class="font-normal text-zinc-600 dark:text-zinc-400">({{ __('you') }})</span>
                                @endif
                            </flux:heading>
                            <flux:text class="truncate">{{ $member->email }}</flux:text>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($this->isOwner)
                            <flux:select
                                wire:key="role-{{ $member->id }}-{{ $member->pivot->role->value }}-{{ $roleResync }}"
                                size="sm"
                                class="!w-32"
                                :aria-label="__('Role for :name', ['name' => $member->name])"
                                wire:change="changeRole({{ $member->id }}, $event.target.value)"
                                data-test="role-select"
                            >
                                @foreach (ClubRole::cases() as $role)
                                    <option value="{{ $role->value }}" @selected($member->pivot->role === $role)>{{ ucfirst($role->value) }}</option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:badge :color="$member->pivot->role === ClubRole::Owner ? 'amber' : 'zinc'">
                                {{ ucfirst($member->pivot->role->value) }}
                            </flux:badge>
                        @endif

                        @if ($isSelf)
                            <flux:button
                                size="sm"
                                variant="subtle"
                                icon="arrow-right-start-on-rectangle"
                                wire:click="removeMember({{ $member->id }})"
                                wire:confirm="{{ __('Leave :club?', ['club' => $club->name]) }}"
                                data-test="leave-club-button"
                            >
                                {{ __('Leave') }}
                            </flux:button>
                        @elseif ($this->isOwner)
                            <flux:button
                                size="sm"
                                variant="subtle"
                                icon="trash"
                                class="text-red-700! dark:text-red-400!"
                                wire:click="removeMember({{ $member->id }})"
                                wire:confirm="{{ __('Remove :name from the club?', ['name' => $member->name]) }}"
                                :aria-label="__('Remove :name', ['name' => $member->name])"
                                data-test="remove-member-button"
                            />
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-card>

    @if ($this->isOwner)
        <x-card :title="__('Invite staff')" :description="__('We email a link that expires in :days days. They must sign in with that address to join.', ['days' => config('pickleq.invitation_ttl_days')])" data-test="invite-card">
            <div class="space-y-6">
                <form wire:submit="invite" class="grid gap-4 sm:grid-cols-[1fr_10rem_auto] sm:items-start" data-test="invite-form">
                    <flux:input wire:model="inviteEmail" type="email" :label="__('Email')" required autocomplete="off" placeholder="name@example.com" />
                    <flux:select wire:model="inviteRole" :label="__('Role')" data-test="invite-role">
                        @foreach (ClubRole::cases() as $role)
                            <option value="{{ $role->value }}">{{ ucfirst($role->value) }}</option>
                        @endforeach
                    </flux:select>
                    <div class="sm:pt-6">
                        <flux:button type="submit" variant="primary" icon="paper-airplane" class="w-full" data-test="invite-submit">{{ __('Send invite') }}</flux:button>
                    </div>
                </form>

                @error('invitation')
                    <flux:callout variant="danger" icon="exclamation-triangle" data-test="invitation-error">
                        <flux:callout.text>{{ $message }}</flux:callout.text>
                    </flux:callout>
                @enderror

                <div>
                    <flux:heading size="sm">{{ __('Pending invitations') }}</flux:heading>
                    @if ($this->invitations->isEmpty())
                        <flux:text class="mt-2" data-test="invitations-empty">{{ __('No pending invitations.') }}</flux:text>
                    @else
                        <ul class="mt-2 divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="invitation-list">
                            @foreach ($this->invitations as $invitation)
                                <li class="flex flex-wrap items-center justify-between gap-3 p-3" wire:key="invitation-{{ $invitation->id }}">
                                    <div class="min-w-0">
                                        <flux:heading class="truncate">{{ $invitation->email }}</flux:heading>
                                        <flux:text size="sm">
                                            {{ __('Invited by :name', ['name' => $invitation->inviter?->name ?? __('a former member')]) }}
                                        </flux:text>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:badge size="sm">{{ ucfirst($invitation->role->value) }}</flux:badge>
                                        @if ($invitation->isExpired())
                                            <flux:badge size="sm" color="red" data-test="invitation-expired">{{ __('Expired') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="green">{{ __('Expires :when', ['when' => $invitation->expires_at->diffForHumans()]) }}</flux:badge>
                                        @endif
                                        <flux:button size="sm" icon="arrow-path" wire:click="resendInvitation({{ $invitation->id }})" data-test="resend-invitation-button">{{ __('Resend') }}</flux:button>
                                        <flux:button
                                            size="sm"
                                            variant="subtle"
                                            icon="x-mark"
                                            wire:click="revokeInvitation({{ $invitation->id }})"
                                            wire:confirm="{{ __('Revoke the invitation for :email?', ['email' => $invitation->email]) }}"
                                            :aria-label="__('Revoke invitation for :email', ['email' => $invitation->email])"
                                            data-test="revoke-invitation-button"
                                        />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </x-card>
    @endif
</section>

