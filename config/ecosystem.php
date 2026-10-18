<?php

return [

    /*
    |--------------------------------------------------------------------------
    | InfoDot identity (redeem)
    |--------------------------------------------------------------------------
    | This app has its own database, so a handoff token from InfoDot cannot be
    | found locally. When INFODOT_SERVICE_SECRET is set, the token is sent to
    | InfoDot's redeem endpoint instead (same value as ECOSYSTEM_SECRET_PULSE in
    | InfoDot's .env, where PULSE is this platform's key). Without a secret the
    | app only accepts tokens present in its own database, which is the old
    | shared-database behaviour. InfoDot also refuses redeem for platforms it
    | has not marked live.
    */

    'infodot' => [
        'redeem_url' => env('INFODOT_REDEEM_URL', 'https://infodot.co.za/api/ecosystem/redeem'),
        'platform' => env('INFODOT_PLATFORM_KEY', 'pulse'),
        'secret' => env('INFODOT_SERVICE_SECRET'),
        'timeout' => (int) env('INFODOT_REDEEM_TIMEOUT', 5),
        'provision' => (bool) env('INFODOT_PROVISION_USERS', true),
    ],

];
