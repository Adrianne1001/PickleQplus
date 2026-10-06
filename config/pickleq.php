<?php

use App\Domain\Stars\StarRating;

return [

    /*
    | Maximum number of clubs a single user may own.
    */
    'max_owned_clubs' => (int) env('PICKLEQ_MAX_OWNED_CLUBS', 5),

    /*
    | Default number of courts for newly created clubs.
    */
    'default_courts' => 4,

    /*
    | Default star bands: five ascending DUPR thresholds. Below the first is
    | 1 star, at or above the last is 6 stars.
    */
    'star_bands' => StarRating::DEFAULT_BANDS,

    /*
    | Allowed DUPR rating range for players.
    */
    'rating_min' => 2.0,
    'rating_max' => 8.0,

    /*
    | Staff invitations: days until an invite link expires, and how many
    | invites a club may send per hour.
    */
    'invitation_ttl_days' => 7,
    'invitations_per_hour' => 20,

    /*
    | Signup abuse guard: registration POSTs allowed per IP per hour.
    */
    'registrations_per_hour' => 5,

    /*
    | Roster CSV import limits, and the stars given to new players imported
    | without a DUPR rating.
    */
    'import_max_bytes' => 1024 * 1024,
    'import_max_rows' => 1000,
    'import_default_stars' => 1,

    /*
    | Minutes a roster import preview is kept in the cache before it expires.
    */
    'import_preview_ttl_minutes' => 30,

    /*
    | Balanced rotation engine (PLAN.md section 3). Lowest total cost wins:
    |   |stars(A) - stars(B)| * star_balance + repeat partners * repeat_partner
    |   + repeat opponents * repeat_opponent + skipped priority ranks * skipped_priority
    | One star of imbalance outweighs skipping one queue rank; repeating a
    | partner costs more than repeating an opponent. `window` is how many of
    | the highest-priority waiting players are searched (minimum 4).
    | `avg_match_minutes` is the fallback match length for wait estimates.
    */
    'rotation' => [
        'star_balance' => 3,
        'repeat_partner' => 4,
        'repeat_opponent' => 1.5,
        'skipped_priority' => 2,
        'window' => 8,
        'avg_match_minutes' => 15,
    ],

];
