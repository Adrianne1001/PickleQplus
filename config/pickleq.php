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

];
