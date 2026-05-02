<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hostinger shared hosting can't run a long-lived `queue:work`. Drain the
// queue once a minute via the scheduler instead. --stop-when-empty exits as
// soon as the queue is clear so the process doesn't outlive its tick.
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
