<?php

/*
|--------------------------------------------------------------------------
| Publishing daily metric snapshots to Dot.Analytics
|--------------------------------------------------------------------------
| Server to server, one signed POST a day, queued. Nothing is sent until ALL of the url, the
| secret and the company uuid are set. The secret is this platform's
| ANALYTICS_INGEST_SECRET_<KEY> value from Dot.Analytics' .env (same value on both sides).
|
| Pulse is one global network and has no company ownership of content, so what is sent describes
| the platform as a whole, filed under the single company named here (the operator's own).
*/

return [

    // The full ingest URL, ending in /api/ingest/snapshots. Blank = publishing is off.
    'url' => env('ANALYTICS_INGEST_URL'),

    // Blank = publishing is off.
    'secret' => env('ANALYTICS_INGEST_SECRET'),

    // This platform's key in Dot.Analytics' metric catalogue.
    'platform' => env('ANALYTICS_PLATFORM_KEY', 'pulse'),

    // InfoDot company uuid the platform-wide numbers are filed under. Blank = publishing is off.
    'company_uuid' => env('ANALYTICS_COMPANY_UUID'),

    // Also send the operator's aggregate counts (R-41) next to the three catalogue metrics. Dot.Analytics drops keys
    // its catalogue does not list, so this is safe to leave on; set false to send the three only.
    'extended' => env('ANALYTICS_EXTENDED_METRICS', true),

    // Seconds, for connecting and for the whole request.
    'timeout' => 5,

    // After `threshold` failed deliveries in a row (5xx, 429, no connection) Analytics is left alone
    // for `cooldown` seconds; then one trial goes through, and a success closes the breaker.
    'breaker' => [
        'threshold' => 3,
        'cooldown' => 300,
    ],

];
