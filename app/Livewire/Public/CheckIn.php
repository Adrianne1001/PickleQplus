<?php

namespace App\Livewire\Public;

use App\Models\Player;
use App\Models\PlaySession;
use App\Services\SelfCheckInService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Public self check-in, reached through the venue QR (/checkin/{token}).
 * The token is re-resolved on every request, so regenerating it or ending the
 * session closes the page at once. Throttles and rules live in SelfCheckInService.
 *
 * @phpstan-type SearchRow array{id: string, name: string, needs_gender: bool, status: string|null}
 */
#[Layout('layouts::public')]
#[Title('Check in')]
class CheckIn extends Component
{
    #[Locked]
    public string $token = '';

    public string $search = '';

    /** @var list<SearchRow> */
    #[Locked]
    public array $results = [];

    /** Player the visitor tapped, awaiting confirmation. */
    #[Locked]
    public ?string $selectedId = null;

    /** Optional gender offered at check-in when the player has none. */
    public string $gender = '';

    /** True when the tapped player has no gender yet. Only this flag is exposed, never the value. */
    #[Locked]
    public bool $needsGender = false;

    /** The session password, when the session has one. */
    public string $password = '';

    /** @var array{result: string, player_id: string, public_name: string}|null */
    #[Locked]
    public ?array $done = null;

    private ?PlaySession $resolved = null;

    public function mount(string $token): void
    {
        $this->token = $token;

        if ($this->session() === null) {
            throw new HttpResponseException(response()->view('public.checkin-closed', [], 404));
        }
    }

    public function updatedSearch(SelfCheckInService $checkIns): void
    {
        $this->selectedId = null;
        $this->gender = '';
        $this->needsGender = false;
        $this->results = [];

        $session = $this->session();
        if ($session === null) {
            return;
        }

        $this->results = $checkIns->search($session, $this->token, $this->search, (string) request()->ip());
    }

    /** The visitor tapped a search result: ask them to confirm. */
    public function select(string $playerId): void
    {
        $this->resetErrorBag();
        $this->gender = '';
        $this->selectedId = collect($this->results)->contains('id', $playerId) ? $playerId : null;
        $row = collect($this->results)->firstWhere('id', $this->selectedId);
        $this->needsGender = $this->selectedId !== null && (bool) ($row['needs_gender'] ?? false);

        if ($this->selectedId === null) {
            $this->addError('player', __('Could not find that player. Search for your name again.'));
        }
    }

    public function cancelSelect(): void
    {
        $this->selectedId = null;
        $this->password = '';
        $this->gender = '';
        $this->needsGender = false;
        $this->resetErrorBag();
    }

    public function confirm(SelfCheckInService $checkIns): void
    {
        $session = $this->session();
        if ($session === null || $this->selectedId === null) {
            $this->addError('session', __('Check-in is closed for this session.'));

            return;
        }

        try {
            $this->finish($checkIns->checkIn($session, $this->token, $this->selectedId, (string) request()->ip(), $this->needsGender && $this->gender !== '' ? $this->gender : null, $this->password === '' ? null : $this->password), $session);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        }
    }

    public function render(SelfCheckInService $checkIns): View
    {
        $session = $this->session();
        $selected = collect($this->results)->firstWhere('id', $this->selectedId);

        return view('livewire.public.check-in', [
            'session' => $session,
            'selected' => $selected,
            'requiresPassword' => $session !== null && $checkIns->requiresPassword($session),
            'listEmpty' => $session !== null && ! $checkIns->hasList($session),
            'queueUrl' => $session === null ? null : url('/c/'.($session->club ?? abort(404))->slug.'/s/'.$session->public_id),
        ]);
    }

    /**
     * @param  array{result: string, player_id: string, public_name: string}  $result
     */
    private function finish(array $result, PlaySession $session): void
    {
        $this->done = $result;
        $this->selectedId = null;
        $this->password = '';
        $this->results = [];
        $this->resetErrorBag();

        // Remember "me" on this device so the queue page highlights them.
        $this->dispatch('me-selected', publicId: $session->public_id, playerId: $result['player_id']);
    }

    private function session(): ?PlaySession
    {
        return $this->resolved ??= PlaySession::findByCheckinToken($this->token);
    }
}
