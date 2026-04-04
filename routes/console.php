<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Transit & disruptions — need to be fresh
Schedule::command('news:scrape')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('transit:check-disruptions')->everyFiveMinutes()->withoutOverlapping();

// Events — don't change fast
Schedule::command('events:scrape')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('events:enrich')->everyFifteenMinutes()->withoutOverlapping();

// Bürgeramt slot monitoring — notify users when appointments available
Schedule::command('buergeramt:check')->everyFiveMinutes()->withoutOverlapping();

// GTFS static timetable refresh — VRS updates weekly
Schedule::command('gtfs:refresh')->weeklyOn(1, '03:00')->withoutOverlapping();
