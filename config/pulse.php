<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edit window
    |--------------------------------------------------------------------------
    |
    | How long after posting an author may still edit their own post or
    | comment. 0 turns editing off entirely. Deleting is never windowed.
    |
    */
    'edit_window_minutes' => (int) env('PULSE_EDIT_WINDOW_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Rate limits (attempts per minute, per user)
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'post' => (int) env('PULSE_LIMIT_POST', 20),
        'comment' => (int) env('PULSE_LIMIT_COMMENT', 30),
        'react' => (int) env('PULSE_LIMIT_REACT', 60),
        'report' => (int) env('PULSE_LIMIT_REPORT', 10),
        'join_request' => (int) env('PULSE_LIMIT_JOIN_REQUEST', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    |
    | pulse_reactions.emoji used to accept any string a client sent. Only
    | these are stored now.
    |
    */
    'reaction_emojis' => ['👍', '❤️', '🎉', '💡', '👀'],

    /*
    |--------------------------------------------------------------------------
    | Private communities
    |--------------------------------------------------------------------------
    |
    | After a manager denies a membership request, the same person may not
    | file another one for this many days.
    |
    */
    'join_request_cooldown_days' => (int) env('PULSE_JOIN_REQUEST_COOLDOWN_DAYS', 7),

    // --- entities ---
    // Business pages, offers, roles, needs, media (agent D1). Every number is a ceiling, not a target.
    'entities' => [
        'max_per_user' => (int) env('PULSE_MAX_ENTITIES_PER_USER', 5),
        'max_offers' => (int) env('PULSE_MAX_OFFERS_PER_ENTITY', 100),
        'max_roles' => (int) env('PULSE_MAX_ROLES_PER_ENTITY', 50),
        'max_open_needs' => (int) env('PULSE_MAX_OPEN_NEEDS_PER_USER', 10),
        'max_offer_images' => 8,
        'rules_class' => null, // tests only: the class whose assess() decides moderation instead of Discovery\Moderation\LocalRules
        'need_ttl_days' => (int) env('PULSE_NEED_TTL_DAYS', 30),
        'media' => [
            'disk' => env('PULSE_MEDIA_DISK', 'local'),
            'max_bytes' => 5 * 1024 * 1024,
            'max_pixels' => 16_000_000,
            'sizes' => ['thumb' => 160, 'medium' => 640, 'large' => 1600],
            'uploads_per_hour' => 40,
        ],
    ],
    // --- end entities ---

];
