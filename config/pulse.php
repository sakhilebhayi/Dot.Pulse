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

];
