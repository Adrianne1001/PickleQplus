<?php

use App\Livewire\Inputs\ClubInput;
use App\Models\Club;
use App\Models\User;
use App\Services\ClubService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Create club')] class extends Component {
    public string $name = '';
    public string $dupr_club_id = '';
    public string $default_courts = '';

    public function mount(): void
    {
        $this->authorize('create', Club::class);

        $this->default_courts = (string) config('pickleq.default_courts');
    }

    #[Computed]
    public function hasClubs(): bool
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->clubs()->exists();
    }

    public function create(ClubService $clubs): void
    {
        $this->authorize('create', Club::class);

        $data = (new ClubInput)->create([
            'name' => $this->name,
            'dupr_club_id' => $this->dupr_club_id,
            'default_courts' => $this->default_courts,
        ]);

        /** @var User $user */
        $user = Auth::user();

        $club = $clubs->create($user, $data);

        $this->redirectRoute('clubs.show', $club, navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-xl space-y-6">
    <div>
        @if ($this->hasClubs)
            <flux:heading size="xl" level="1">{{ __('Create a club') }}</flux:heading>
            <flux:subheading>{{ __('Each club has its own staff, players and settings.') }}</flux:subheading>
        @else
            <flux:heading size="xl" level="1" data-test="first-club-heading">{{ __('Create your first club') }}</flux:heading>
            <flux:subheading>
                {{ __('A club is where you run open play: it holds your roster, your staff and your sessions. You will be its owner.') }}
            </flux:subheading>
        @endif
    </div>

    <form wire:submit="create" class="space-y-6">
        <flux:input
            wire:model="name"
            :label="__('Club name')"
            :placeholder="__('Sunset Pickleball Club')"
            required
            autofocus
            maxlength="120"
        />

        <flux:input
            wire:model="dupr_club_id"
            :label="__('DUPR club ID (optional)')"
            :description="__('The 10-digit ID from your DUPR club page. You can add it later.')"
            inputmode="numeric"
            maxlength="10"
            placeholder="1234567890"
        />

        <flux:input
            wire:model="default_courts"
            :label="__('Default courts')"
            :description="__('How many courts a new session starts with.')"
            type="number"
            min="1"
            max="30"
            inputmode="numeric"
        />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit" data-test="create-club-button">
                {{ __('Create club') }}
            </flux:button>

            @if ($this->hasClubs)
                <flux:button variant="ghost" :href="route('dashboard')" wire:navigate>{{ __('Cancel') }}</flux:button>
            @endif
        </div>
    </form>
</section>
