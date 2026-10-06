<?php

use App\Domain\Stars\StarRating;
use App\Enums\LateArrivalPolicy;
use App\Livewire\Inputs\ClubInput;
use App\Models\Club;
use App\Services\ClubService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Club settings')] class extends Component {
    #[Locked]
    public Club $club;

    public string $name = '';
    public string $slug = '';
    public string $dupr_club_id = '';
    public string $default_courts = '';

    /** @var array<int, string> */
    public array $star_bands = [];

    public string $late_arrival_policy = 'minimum';
    public bool $allow_concurrent_sessions = false;

    public bool $public_stats = false;
    public string $leaderboard_min_games = '10';

    public string $confirmName = '';

    public function mount(Club $club): void
    {
        $this->authorize('manageSettings', $club);

        $this->club = $club;
        $this->fillFromClub();
    }

    private function fillFromClub(): void
    {
        $this->name = $this->club->name;
        $this->slug = $this->club->slug;
        $this->dupr_club_id = $this->club->dupr_club_id ?? '';
        $this->default_courts = (string) $this->club->default_courts;
        $this->late_arrival_policy = $this->club->late_arrival_policy->value;
        $this->allow_concurrent_sessions = $this->club->allow_concurrent_sessions;
        $this->public_stats = $this->club->public_stats;
        $this->leaderboard_min_games = (string) $this->club->leaderboard_min_games;
        $this->star_bands = array_map(
            fn ($b): string => number_format((float) $b, 2, '.', ''),
            array_values($this->club->star_bands),
        );
    }

    public function saveDetails(ClubService $clubs): void
    {
        $this->authorize('update', $this->club);

        $data = (new ClubInput)->update($this->club, [
            'name' => $this->name,
            'slug' => $this->slug,
            'dupr_club_id' => $this->dupr_club_id,
            'default_courts' => $this->default_courts,
        ]);

        $slugChanged = $data['slug'] !== $this->club->slug;

        $clubs->update($this->club, $data);

        Flux::toast(variant: 'success', text: __('Club settings saved.'));

        if ($slugChanged) {
            // The page URL contains the slug, so move to the new address.
            $this->redirectRoute('clubs.settings', $this->club, navigate: true);
        }
    }

    public function saveStarBands(ClubService $clubs): void
    {
        $this->authorize('manageSettings', $this->club);

        $bands = (new ClubInput)->starBands($this->star_bands);

        $clubs->updateStarBands($this->club, $bands);

        $this->fillFromClub();

        Flux::toast(variant: 'success', text: __('Star bands saved. Stars were recomputed for DUPR-rated players.'));
    }

    public function saveSessionSettings(ClubService $clubs): void
    {
        $this->authorize('manageSettings', $this->club);

        $clubs->updateSessionSettings($this->club, [
            'late_arrival_policy' => $this->late_arrival_policy,
            'allow_concurrent_sessions' => $this->allow_concurrent_sessions,
        ]);

        $this->fillFromClub();

        Flux::toast(variant: 'success', text: __('Session settings saved.'));
    }

    public function saveStatsSettings(ClubService $clubs): void
    {
        $this->authorize('manageSettings', $this->club);

        $clubs->updateStatsSettings($this->club, [
            'public_stats' => $this->public_stats,
            'leaderboard_min_games' => $this->leaderboard_min_games,
        ]);

        $this->fillFromClub();

        Flux::toast(variant: 'success', text: __('Stats settings saved.'));
    }

    public function resetStarBands(): void
    {
        $this->authorize('manageSettings', $this->club);

        $this->star_bands = array_map(
            fn ($b): string => number_format((float) $b, 2, '.', ''),
            config('pickleq.star_bands'),
        );
        $this->resetErrorBag('star_bands');
    }

    public function deleteClub(ClubService $clubs): void
    {
        $this->authorize('delete', $this->club);

        if (trim($this->confirmName) !== $this->club->name) {
            $this->addError('confirmName', __('Type the club name exactly to confirm.'));

            return;
        }

        $clubs->delete($this->club);

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Live preview of the rating to stars table; empty while the inputs are invalid.
     *
     * @return list<array{stars: int, range: string}>
     */
    #[Computed]
    public function bandPreview(): array
    {
        $bands = [];
        foreach ($this->star_bands as $value) {
            if (! is_numeric($value)) {
                return [];
            }
            $bands[] = (float) $value;
        }

        if (StarRating::validate($bands) !== []) {
            return [];
        }

        $f = fn (float $v): string => number_format($v, 2);
        $rows = [['stars' => 1, 'range' => __('below :max', ['max' => $f($bands[0])])]];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = [
                'stars' => $i + 2,
                'range' => $f($bands[$i]).' – '.$f($bands[$i + 1] - 0.01),
            ];
        }
        $rows[] = ['stars' => 6, 'range' => __(':min and up', ['min' => $f($bands[4])])];

        return $rows;
    }
}; ?>

<section class="w-full max-w-3xl space-y-10">
    <div>
        <flux:heading size="xl" level="1">{{ __('Club settings') }}</flux:heading>
        <flux:subheading>{{ $club->name }}</flux:subheading>
    </div>

    {{-- Details --}}
    <form wire:submit="saveDetails" class="space-y-6" data-test="details-form">
        <flux:heading size="lg">{{ __('Details') }}</flux:heading>

        <flux:input wire:model="name" :label="__('Club name')" required maxlength="120" />

        <flux:field>
            <flux:label>{{ __('URL slug') }}</flux:label>
            <flux:input wire:model.live.debounce.300ms="slug" required maxlength="50" />
            <flux:description data-test="slug-url">
                {{ route('clubs.show', ['club' => $slug !== '' ? $slug : '...']) }}
            </flux:description>
            <flux:error name="slug" />
        </flux:field>

        <flux:input
            wire:model="dupr_club_id"
            :label="__('DUPR club ID')"
            :description="__('10 digits. Leave empty if your club is not on DUPR.')"
            inputmode="numeric"
            maxlength="10"
        />

        <flux:input
            wire:model="default_courts"
            :label="__('Default courts')"
            type="number"
            min="1"
            max="30"
            inputmode="numeric"
        />

        <flux:button variant="primary" type="submit" data-test="save-details-button">{{ __('Save details') }}</flux:button>
    </form>

    <flux:separator />

    {{-- Star bands (P1.6) --}}
    <form wire:submit="saveStarBands" class="space-y-6" data-test="star-bands-form">
        <div>
            <flux:heading size="lg">{{ __('Star bands') }}</flux:heading>
            <flux:subheading>
                {{ __('Five rating thresholds turn a DUPR rating into 1 to 6 stars. Saving recomputes stars for every DUPR-rated player in this club. Players with manually set stars are not changed.') }}
            </flux:subheading>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
            @foreach ($star_bands as $i => $band)
                <flux:input
                    wire:model.live.debounce.300ms="star_bands.{{ $i }}"
                    :label="'★'.($i + 2).' '.__('from')"
                    type="number"
                    step="0.01"
                    min="2"
                    max="8"
                    inputmode="decimal"
                />
            @endforeach
        </div>

        @error('star_bands')
            <flux:text class="font-medium text-red-600 dark:text-red-400">{{ $message }}</flux:text>
        @enderror
        @error('star_bands.*')
            <flux:text class="font-medium text-red-600 dark:text-red-400">{{ $message }}</flux:text>
        @enderror

        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="band-preview">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-start dark:bg-zinc-900">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-start font-medium">{{ __('DUPR rating') }}</th>
                        <th scope="col" class="px-4 py-2 text-start font-medium">{{ __('Stars') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->bandPreview as $row)
                        <tr>
                            <td class="px-4 py-2 tabular-nums">{{ $row['range'] }}</td>
                            <td class="px-4 py-2"><x-star-rating :stars="$row['stars']" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="px-4 py-3 text-zinc-500">
                                {{ __('Enter five ascending thresholds between 2.00 and 8.00 to see the preview.') }}
                            </td>
                        </tr>
                    @endforelse
                    <tr>
                        <td class="px-4 py-2 text-zinc-500">{{ __('No DUPR rating') }}</td>
                        <td class="px-4 py-2 text-zinc-500">{{ __('Set manually (1 to 6)') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap gap-3">
            <flux:button variant="primary" type="submit" data-test="save-star-bands-button">{{ __('Save star bands') }}</flux:button>
            <flux:button type="button" variant="ghost" wire:click="resetStarBands">{{ __('Reset to defaults') }}</flux:button>
        </div>
    </form>

    <flux:separator />

    {{-- Sessions (P2.1b) --}}
    <form wire:submit="saveSessionSettings" class="space-y-6" data-test="session-settings-form">
        <flux:heading size="lg">{{ __('Sessions') }}</flux:heading>

        <flux:radio.group wire:model="late_arrival_policy" :label="__('Late arrivals and players returning from break')" data-test="late-arrival-policy">
            <flux:radio value="{{ LateArrivalPolicy::Minimum->value }}" :label="__('Join at current minimum')" :description="__('Line up with whoever has played least, so latecomers are neither stuck at the back nor jump ahead.')" />
            <flux:radio value="{{ LateArrivalPolicy::Front->value }}" :label="__('Front of queue')" :description="__('No credit: they have played the fewest games, so they are first in line.')" />
            <flux:radio value="{{ LateArrivalPolicy::Back->value }}" :label="__('Back of queue')" :description="__('They line up behind everyone who is already waiting or playing.')" />
        </flux:radio.group>
        <flux:error name="late_arrival_policy" />

        <flux:field variant="inline">
            <flux:checkbox wire:model="allow_concurrent_sessions" data-test="allow-concurrent" />
            <flux:label>{{ __('Allow several live sessions at once') }}</flux:label>
            <flux:description>{{ __('Off by default. A player can still be checked in to only one live session at a time.') }}</flux:description>
        </flux:field>
        <flux:error name="allow_concurrent_sessions" />

        <flux:button variant="primary" type="submit" data-test="save-session-settings-button">{{ __('Save session settings') }}</flux:button>
    </form>

    <flux:separator />

    {{-- Stats (P5.2b) --}}
    <form wire:submit="saveStatsSettings" class="space-y-6" data-test="stats-settings-form">
        <flux:heading size="lg">{{ __('Stats') }}</flux:heading>

        <flux:field variant="inline">
            <flux:checkbox wire:model="public_stats" data-test="public-stats" />
            <flux:label>{{ __('Public stats') }}</flux:label>
            <flux:description>{{ __('Off by default. Shows a public leaderboard and the final results of ended sessions to anyone with the link. Only display names are shown.') }}</flux:description>
        </flux:field>
        <flux:error name="public_stats" />

        @if ($club->public_stats)
            <x-copy-field :label="__('Public leaderboard link')" :value="route('public.stats', $club)" test="public-stats-url" />
        @endif

        <flux:input
            wire:model="leaderboard_min_games"
            :label="__('Leaderboard minimum games')"
            :description="__('Players need at least this many games in the period to be ranked (1 to 100).')"
            type="number"
            min="1"
            max="100"
            inputmode="numeric"
            data-test="leaderboard-min-games"
        />

        <flux:button variant="primary" type="submit" data-test="save-stats-settings-button">{{ __('Save stats settings') }}</flux:button>
    </form>

    <flux:separator />

    {{-- Danger zone --}}
    <div class="space-y-4 rounded-lg border border-red-300 p-5 dark:border-red-900" data-test="danger-zone">
        <div>
            <flux:heading size="lg" class="text-red-600 dark:text-red-400">{{ __('Danger zone') }}</flux:heading>
            <flux:subheading>{{ __('Deleting a club permanently removes its players, members and history.') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="delete-club">
            <flux:button variant="danger" data-test="delete-club-button">{{ __('Delete club') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="delete-club" :show="$errors->has('confirmName')" focusable class="max-w-lg">
        <form wire:submit="deleteClub" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this club?') }}</flux:heading>
                <flux:subheading>
                    {{ __('This cannot be undone. Type :name to confirm.', ['name' => $club->name]) }}
                </flux:subheading>
            </div>

            <flux:input wire:model="confirmName" :label="__('Club name')" autocomplete="off" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="confirm-delete-club-button">{{ __('Delete club') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
