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
