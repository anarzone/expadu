<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Transit & disruptions
Schedule::command('news:scrape')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('transit:check-disruptions')->everyTenMinutes()->withoutOverlapping();

// Events
Schedule::command('events:scrape')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('events:enrich')->everyFifteenMinutes()->withoutOverlapping();

// Bürgeramt slot monitoring — rare + time-sensitive, keep frequent
Schedule::command('buergeramt:check')->everyFiveMinutes()->withoutOverlapping();

// Transit delay alerts — check every 15 min, only notify for >10 min delays
Schedule::command('transit:check-delays')->everyFifteenMinutes()->withoutOverlapping();

// Weather alerts — every 2 hours, only during commute windows
Schedule::command('weather:check-alerts')->cron('0 */2 * * *')->withoutOverlapping();

// Rhine flood level — hourly, in-app only (no push)
Schedule::command('rhine:check')->hourly()->withoutOverlapping();

// Leave-by reminders — proactive push before user needs to leave for work/course/etc
Schedule::command('commute:send-leaveby-reminders')->everyFiveMinutes()->withoutOverlapping();

// Event reminders — 1 day before events user is attending
Schedule::command('events:send-reminders')->dailyAt('18:00')->withoutOverlapping();

// Notification health check — hourly automated monitoring
Schedule::command('notification:health-check')->hourly()->withoutOverlapping();

// Restaurant/spot data refresh — daily at 04:00 until coverage is complete, then switch to weekly
Schedule::command('restaurants:scrape')->dailyAt('04:00')->withoutOverlapping();

// GTFS static timetable refresh — VRS updates weekly
Schedule::command('gtfs:refresh')->weeklyOn(1, '03:00')->withoutOverlapping();
