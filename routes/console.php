<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send scheduled email campaigns when their time arrives. Only fires if a
// `schedule:run` cron is configured; otherwise admins can use the in-app
// "Run scheduled now" action or run `php artisan campaigns:run-due` manually.
Schedule::command('campaigns:run-due')->everyMinute()->withoutOverlapping();
