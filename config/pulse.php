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
        'hiring_signal_days' => (int) env('PULSE_HIRING_SIGNAL_DAYS', 60),
        'media' => [
            'disk' => env('PULSE_MEDIA_DISK', 'local'),
            'max_bytes' => 5 * 1024 * 1024,
            'max_pixels' => 16_000_000,
            'sizes' => ['thumb' => 160, 'medium' => 640, 'large' => 1600],
            'uploads_per_hour' => 40,
        ],
    ],
    // --- end entities ---
    // --- lines ---
    // Agent D3: contact, conversations, trust. Edited only inside this block.

    /*
    |--------------------------------------------------------------------------
    | Age gate
    |--------------------------------------------------------------------------
    |
    | Pulse is for people this age and over (D25). Declared at onboarding (pulse_profiles.age_confirmed_at); nobody
    | without the declaration can contact or be contacted, and no profile under this age is ever discoverable.
    |
    */
    'age_min' => 18,

    /*
    |--------------------------------------------------------------------------
    | Safe handover guidance (D26)
    |--------------------------------------------------------------------------
    |
    | Shown beside lines about buying, selling and hiring. Worded as guidance, never as protection.
    |
    */
    'safety' => [
        'trade' => [
            'Pay inside your own banking app. Never trust a screenshot of a payment.',
            'Meet in a public place, in daylight where you can, for any hand over.',
            'Tell one person you trust where you are going and who you are meeting.',
            'If someone pushes you to pay quickly or to move to another app, stop and report it.',
        ],
        'work' => [
            'A real employer never asks you to pay to be considered or to start.',
            'Keep your ID number, bank details and home address out of the chat until you have checked who you are talking to.',
            'Meet or interview somewhere public or on a call you set up yourself.',
        ],
    ],

    'lines' => [
        // Attempts per minute per person. Messages 30; first contacts are also bounded by the knock budget.
        'rate_limits' => [
            'message' => (int) env('PULSE_LIMIT_MESSAGE', 30),
            'knock' => (int) env('PULSE_LIMIT_KNOCK', 20),
            'report' => (int) env('PULSE_LIMIT_LINES_REPORT', 10),
        ],

        // First contacts to strangers per window (D18). A reply from a stranger refills one, up to bonus_cap.
        'knock_budget' => [
            'established' => 7,
            'new' => 3,
            'new_days' => 7,
            'window_days' => 7,
            'bonus_cap' => 5,
        ],

        'request_expiry_days' => 14,        // an unanswered request closes as expired and the sender is told
        'redeclined_cooldown_days' => 30,   // after "not now", the same ask waits this long
        'message_max' => 2000,
        'review_max' => 1000,
        'review_window_days' => 60,         // how long after a confirmed result a review can be written
        'quote_max_validity_days' => 90,
        'door_knock_cap' => 10,
        'spam_shrink' => 2,                 // knock budget lost when a moderator confirms spam

        // What the sender is shown when a request is declined. The first is the default.
        'decline_reasons' => [
            'not_now' => 'Not now',
            'not_a_fit' => 'Not a fit',
            'no_capacity' => 'No capacity right now',
            'out_of_area' => 'Outside my area',
        ],
    ],
    // --- end lines ---

    // --- discovery & moderation (R-41 D2) ---

    /*
    |--------------------------------------------------------------------------
    | Local moderation rules
    |--------------------------------------------------------------------------
    |
    | The deterministic first pass (Discovery\Moderation\LocalRules). It needs no model key. Extra terms can be
    | added without a deploy through PULSE_BLOCKED_TERMS / PULSE_HOLD_TERMS (comma separated).
    |
    |   blocked_terms  published never; the author is told why
    |   hold_terms     waits for a person
    |
    */
    'moderation' => [
        'blocked_terms' => array_values(array_filter(array_merge([
            'kill yourself', 'buy followers', 'cheap viagra', 'order viagra', 'online casino bonus',
            'nude pics for sale', 'child porn', 'cp for sale',
        ], array_map('trim', explode(',', (string) env('PULSE_BLOCKED_TERMS', '')))))),
        'hold_terms' => array_values(array_filter(array_merge([
            'escort service', 'sugar daddy', 'sugar mummy', 'hookup', 'miracle cure', 'cure for cancer',
            'fixed matches', 'betting tips', 'adult content', 'xxx',
        ], array_map('trim', explode(',', (string) env('PULSE_HOLD_TERMS', '')))))),
        'max_links' => (int) env('PULSE_MOD_MAX_LINKS', 3),
        'block_links' => (int) env('PULSE_MOD_BLOCK_LINKS', 6),
        'new_account_hours' => (int) env('PULSE_MOD_NEW_ACCOUNT_HOURS', 24),
        'velocity_hold' => (int) env('PULSE_MOD_VELOCITY_HOLD', 8),
        'velocity_window_minutes' => (int) env('PULSE_MOD_VELOCITY_WINDOW', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Digest
    |--------------------------------------------------------------------------
    */
    'digest' => [
        'max_items' => 6,
        'grace_hours' => 10,
    ],

    // --- security (R-41 security pass) ---
    // Response headers. The Content-Security-Policy is enforced; SECURITY_CSP_REPORT_ONLY=true switches it to
    // report-only without a deploy. The Reverb values describe where the browser opens its websocket (not the
    // PHP backend's own publish address), so connect-src can allow exactly that origin.
    'security' => [
        'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
        // Searches and typed-ahead suggestions per person per minute: scraping and enumeration cost something. 0 turns one off.
        'rate_limits' => [
            'search' => (int) env('PULSE_LIMIT_SEARCH', 90),
            'suggest' => (int) env('PULSE_LIMIT_SUGGEST', 150),
        ],
        'reverb_public' => [
            'host' => env('REVERB_HOST', 'localhost'),
            'port' => env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
        ],
    ],
    // --- end security ---

    // --- privacy (R-41 hardening) ---
    'privacy' => [
        // K-anonymity for anonymous talent cards (D23): an employer is shown an anonymous card only when this many
        // professionals share its primary skills and region. Never set it below 5 in production; tests lower it.
        'talent_cohort_min' => (int) env('PULSE_TALENT_COHORT_MIN', 5),
    ],
    // --- end privacy ---

    // --- invitations (R-41 hardening) ---
    'invitations' => [
        'ttl_days' => (int) env('PULSE_INVITATION_TTL_DAYS', 14),
        'per_person_hour' => (int) env('PULSE_LIMIT_INVITES_HOUR', 20),
        'per_page_day' => (int) env('PULSE_LIMIT_INVITES_PAGE_DAY', 50),
    ],
    // --- end invitations ---

];
