<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send scheduled email campaigns when their time arrives. This is the cleanest
// driver and fires reliably IF a `schedule:run` cron is configured. When no
// cron exists, scheduled sends are still triggered automatically by normal API
// traffic (App\Http\Middleware\TriggerDueCampaigns), and admins can always use
// the in-app "Run scheduled now" action or `php artisan campaigns:run-due`.
Schedule::command('campaigns:run-due')->everyMinute()->withoutOverlapping();
