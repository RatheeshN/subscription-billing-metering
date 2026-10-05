<?php

use App\Jobs\AggregateDailyUsageJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new AggregateDailyUsageJob)->everyMinute()->onOneServer();
Schedule::command('billing:dispatch')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();
