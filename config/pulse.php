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

];
