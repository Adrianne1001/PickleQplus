<?php

namespace App\Models;

use App\Enums\ClubRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivot row of club_user.
 *
 * @property int $id
 * @property int $club_id
 * @property int $user_id
 * @property ClubRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClubMembership extends Pivot
{
    protected $table = 'club_user';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ClubRole::class,
        ];
    }
}
