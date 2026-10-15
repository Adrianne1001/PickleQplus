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
        'winners_stay_max_wins' => 2,

        // Social mix (P7.4g): the balanced flow ignoring stars, partner repeats weigh
        // heavily, then opponents are spread. Shares `window` and queue priority.
        'social' => [
            'repeat_partner' => 6,
            'repeat_opponent' => 2,
            'skipped_priority' => 2,
        ],
    ],

    /*
    | Rotation modes a session may currently be set to (P7.4). Each later
    | P7.4 item adds its mode here when it lands.
    */
    'rotation_modes_enabled' => ['balanced', 'mixed', 'skill_courts', 'social'],

    /*
    | Guest sessions (Phase 14): a hidden, temporary club for someone with no account.
    | Caps are enforced in services. A guest club is deleted `idle_hours` after its last
    | activity, and never lives longer than `max_hours` after creation. `starts_per_hour`
    | is the per-IP start limit, `max_active` the circuit breaker on unexpired guest clubs.
    */
    'guest' => [
        'max_courts' => (int) env('PICKLEQ_GUEST_MAX_COURTS', 6),
        'max_checked_in' => (int) env('PICKLEQ_GUEST_MAX_CHECKED_IN', 40),
        'max_roster' => (int) env('PICKLEQ_GUEST_MAX_ROSTER', 60),
        'max_listed' => (int) env('PICKLEQ_GUEST_MAX_LISTED', 60),
        'max_sessions' => 1,
        'idle_hours' => (int) env('PICKLEQ_GUEST_IDLE_HOURS', 24),
        'max_hours' => (int) env('PICKLEQ_GUEST_MAX_HOURS', 72),
        'starts_per_hour' => (int) env('PICKLEQ_GUEST_STARTS_PER_HOUR', 5),
        'max_active' => (int) env('PICKLEQ_GUEST_MAX_ACTIVE', 500),
        // Seconds a start waits for the start lock (it serialises the max_active count and the insert).
        'start_lock_wait' => 5,
    ],

];
