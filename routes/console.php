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
Schedule::command('events:curate')->hourly()->withoutOverlapping();
Schedule::command('events:expire')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('events:send-occurrence-reminders')->everyFiveMinutes()->withoutOverlapping();

// Transit delay alerts — check every 15 min, only notify for >10 min delays
Schedule::command('transit:check-delays')->everyFifteenMinutes()->withoutOverlapping();

// Weather alerts — every 2 hours, only during commute windows
Schedule::command('weather:check-alerts')->cron('0 */2 * * *')->withoutOverlapping();

// Rhine flood level — hourly, in-app only (no push)
Schedule::command('rhine:check')->hourly()->withoutOverlapping();

// Event reminders — 1 day before events user is attending
Schedule::command('events:send-reminders')->dailyAt('18:00')->withoutOverlapping();

// Notification health check — hourly automated monitoring
Schedule::command('notification:health-check')->hourly()->withoutOverlapping();

// Restaurant/café scraping is DISABLED for now. v2 de-emphasises the
// restaurant/café layer, and the scraper stores raw OSM opening_hours strings
// (the composer had to be hardened against them). The command still exists for
// manual runs; re-enable here (weekly, not daily) only if that layer comes back
// into scope.
// Schedule::command('restaurants:scrape')->dailyAt('04:00')->withoutOverlapping();

// Place photos from Wikimedia (wikidata/wikipedia links + Commons geosearch) —
// weekly, so newly imported spots pick up an openly-licensed photo where one
// exists. Idempotent: only fills spots that still have no photo.
Schedule::command('spots:fetch-photos')->weeklyOn(0, '04:30')->withoutOverlapping();

// External API health monitoring — every 5 minutes
Schedule::command('api:health')->everyFiveMinutes()->withoutOverlapping();

// GTFS static timetable refresh — VRS updates weekly
Schedule::command('gtfs:refresh')->weeklyOn(1, '03:00')->withoutOverlapping();

// Bureaucracy task deadline reminders — daily morning push for urgent/overdue tasks
Schedule::command('bureaucracy:remind')->timezone('Europe/Berlin')->dailyAt('09:00')->withoutOverlapping();

// Live Bürgeramt slot availability for the Offices grid — during booking
// hours only (the city releases day-of batches in the mornings). ~90 runs/day
// ≈ 270 requests; SMARTCJM_SLOTS_ENABLED is the posture switch (robots.txt
// disallows crawling, owner accepted the risk 2026-07-02).
Schedule::command('slots:check')
    ->timezone('Europe/Berlin')
    ->everyTenMinutes()
    ->between('6:00', '21:00')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.smartcjm.enabled'));

Schedule::command('controls:synthetic-disruption')->everyThirtyMinutes()->withoutOverlapping();
