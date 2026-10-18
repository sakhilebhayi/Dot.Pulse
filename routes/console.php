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
