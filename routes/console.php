<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A dead queue worker must not hide new posts forever: hand anything stuck in
// "pending" to the human moderation queue.
Schedule::command('pulse:sweep-pending')->everyFiveMinutes()->withoutOverlapping();

// One signed, aggregate-only snapshot a day for Dot.Analytics. Does nothing until the url, secret and
// company uuid are all configured; an Analytics outage only ever affects the queued job.
Schedule::command('pulse:publish-metrics')->dailyAt('00:10')->timezone('UTC')->withoutOverlapping();

// --- lines ---
// Expire stale quote slips, close requests nobody answered in 14 days (and tell the sender), lapse open doors, and
// reveal reviews whose blind period is over.
Schedule::command('pulse:lines:sweep')->hourly()->withoutOverlapping();
// --- end lines ---
// --- R-41 discovery (D2) ---
// The host has ONE shared crontab, so every entry here is guarded against overlapping itself.
Schedule::command('pulse:index')->hourly()->withoutOverlapping();
Schedule::command('pulse:match')->everyTenMinutes()->withoutOverlapping();
Schedule::command('pulse:digest')->hourly()->withoutOverlapping();
Schedule::command('pulse:expire')->hourly()->withoutOverlapping();
// --- end R-41 discovery (D2) ---
