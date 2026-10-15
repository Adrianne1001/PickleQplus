<?php

namespace App\Livewire\Sessions\Concerns;

use App\Concerns\PlayerValidationRules;
use App\Models\Club;
use App\Models\Player;
use App\Rules\DuprPlayerId;
use App\Services\CheckInService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The quick-add row and its inline form, shared by the board's check-in panel and the
 * session form. The host component has a public string $search and a quickClub() method.
 * Field errors live under the `quick.` prefix.
 */
trait QuickAddsPlayer
{
    use PlayerValidationRules;

    public bool $quickOpen = false;

    /** @var array<string, mixed> The inline form fields: name, stars, dupr_id, gender. */
    public array $quick = ['name' => '', 'stars' => '', 'dupr_id' => '', 'gender' => ''];

    abstract protected function quickClub(): Club;

    public function openQuickAdd(): void
    {
        $club = $this->quickClub();
        $this->authorize('create', [Player::class, $club]);

        $this->resetErrorBag(['quick', 'quick.name', 'quick.stars', 'quick.dupr_id', 'quick.gender']);
        $this->quick = ['name' => trim($this->search), 'stars' => '', 'dupr_id' => '', 'gender' => ''];
        $this->quickOpen = true;
    }

    public function closeQuickAdd(): void
    {
        $this->quickOpen = false;
        $this->resetErrorBag(['quick', 'quick.name', 'quick.stars', 'quick.dupr_id', 'quick.gender']);
    }

    /**
     * What the quick-add row should show for the current search.
     *
     * @return array{show: bool, deactivated: ?Player}
     */
    protected function quickAddState(): array
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return ['show' => false, 'deactivated' => null];
        }

        $found = app(CheckInService::class)->findPlayerByName($this->quickClub(), $term);

        return [
            'show' => $found === null,
            'deactivated' => $found !== null && ! $found->active ? $found : null,
        ];
    }

    /** A form field as a string; the client could send anything. */
    private function quickField(string $key): string
    {
        $value = $this->quick[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /**
     * The form fields as service input.
     *
     * @return array{name: string, stars: ?string, dupr_id: ?string, gender: string}
     */
    protected function quickInput(): array
    {
        return [
            'name' => trim($this->quickField('name')),
            'stars' => $this->quickField('stars') === '' ? null : $this->quickField('stars'),
            'dupr_id' => DuprPlayerId::normalize($this->quickField('dupr_id')),
            'gender' => $this->quickField('gender'),
        ];
    }

    /**
     * Validate the inline form against the player rules. Stars are optional (blank means skill not set).
     *
     * @return array{name: string, stars: ?string, dupr_id: ?string, gender: string}
     *
     * @throws ValidationException
     */
    protected function validatedQuick(): array
    {
        $input = $this->quickInput();
        $check = [...$input, 'gender' => $input['gender'] === '' ? null : $input['gender']];
        $rules = array_intersect_key($this->playerRules($this->quickClub(), null, ['rating_source' => 'manual'] + $check), array_flip(['name', 'stars', 'dupr_id', 'gender']));
        if ($input['stars'] === null) {
            unset($rules['stars']); // optional: a blank value lists the player at 3 stars with "skill not set"
        }

        try {
            Validator::make($check, $rules)->validate();
        } catch (ValidationException $e) {
            throw $this->prefixed($e);
        }

        return $input;
    }

    /** Re-key a service's field errors (name, stars, ...) onto the inline form. */
    protected function prefixed(ValidationException $e): ValidationException
    {
        $errors = [];
        foreach ($e->errors() as $key => $messages) {
            $errors[in_array($key, ['name', 'stars', 'dupr_id', 'gender'], true) ? "quick.{$key}" : $key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
