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

        // R-50/R-61: during a session, ask InfoDot whether the current company still holds this platform and whether
        // the platform is available (a clear answer is cached one minute per company and remembered six hours; an
        // outage or a misconfiguration never locks anyone out). See EnsureCompanyEntitled.
        'entitlement_check' => (bool) env('PULSE_ENTITLEMENT_CHECK', true),
        // Optional full URL of the read endpoint, with {uuid} for the company; blank = beside the redeem endpoint.
        'entitlement_url' => env('PULSE_ENTITLEMENT_URL'),
        'entitlement_timeout' => (int) env('PULSE_ENTITLEMENT_TIMEOUT', 3),
    ],

];
