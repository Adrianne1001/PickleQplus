<?php

namespace App\Livewire\Public;

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
 * @phpstan-type SearchRow array{id: string, name: string, nickname: string|null, status: string|null}
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

    /** Optional nickname offered at check-in when the player has none. */
    public string $nickname = '';

    public bool $registering = false;

    public string $regName = '';

    public string $regNickname = '';

    public string $regDuprId = '';

    public string $regStars = '';

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
        $this->nickname = '';
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
        $this->nickname = '';
        $this->selectedId = collect($this->results)->contains('id', $playerId) ? $playerId : null;

        if ($this->selectedId === null) {
            $this->addError('player', __('Could not find that player. Search for your name again.'));
        }
    }

    public function cancelSelect(): void
    {
        $this->selectedId = null;
        $this->nickname = '';
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
            $this->finish($checkIns->checkIn($session, $this->token, $this->selectedId, $this->nickname === '' ? null : $this->nickname, (string) request()->ip()), $session);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        }
    }

    public function startRegister(): void
    {
        $this->resetErrorBag();
        $this->registering = true;
        $this->selectedId = null;
        $this->regName = $this->regName !== '' ? $this->regName : trim($this->search);
    }

    public function cancelRegister(): void
    {
        $this->registering = false;
        $this->resetErrorBag();
    }

    public function register(SelfCheckInService $checkIns): void
    {
        $session = $this->session();
        if ($session === null) {
            $this->addError('session', __('Check-in is closed for this session.'));

            return;
        }

        try {
            $this->finish($checkIns->register(
                $session,
                $this->token,
                $this->regName,
                $this->regNickname,
                $this->regDuprId === '' ? null : $this->regDuprId,
                $this->regStars,
                (string) request()->ip(),
            ), $session);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        }
    }

    public function render(): View
    {
        $session = $this->session();
        $selected = collect($this->results)->firstWhere('id', $this->selectedId);

        return view('livewire.public.check-in', [
            'session' => $session,
            'selected' => $selected,
            'levels' => self::LEVELS,
            'queueUrl' => $session === null ? null : url('/c/'.($session->club()->value('slug')).'/s/'.$session->public_id),
        ]);
    }

    /** Plain-language descriptions for the self-rated stars. */
    public const LEVELS = [
        1 => 'New to pickleball',
        2 => 'Learning the rules and basic strokes',
        3 => 'Consistent rallies, learning dinks',
        4 => 'Solid all-round, third-shot drops',
        5 => 'Advanced, strategic play',
        6 => 'Tournament level',
    ];

    /**
     * @param  array{result: string, player_id: string, public_name: string}  $result
     */
    private function finish(array $result, PlaySession $session): void
    {
        $this->done = $result;
        $this->selectedId = null;
        $this->registering = false;
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
