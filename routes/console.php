<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// schedule:run starts due commands in the order they are defined here, and a
// foreground command blocks every command after it. So the time-critical alert
// checks come first and run in the foreground, and every batch job (scrapers,
// importers, enrichers, health sweeps) runs in the background so it can never
// hold an alert check behind it.
//
// Time-critical locks expire after one interval: if a check is killed mid-run
// (deploy, OOM), the next slot runs instead of being skipped for the default
// 24 hours. The entrypoint also clears stale schedule locks on every boot.

// Transit disruptions and delays (time-critical)
Schedule::command('transit:check-disruptions')->everyTenMinutes()->withoutOverlapping(10);
// Transit delay alerts — check every 15 min, only notify for >10 min delays
Schedule::command('transit:check-delays')->everyFifteenMinutes()->withoutOverlapping(15);

// Event reminders — occurrence reminders every 5 min, and 1 day before events
// the user is attending (time-critical)
Schedule::command('events:send-occurrence-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('events:send-reminders')->dailyAt('18:00')->withoutOverlapping(60);
// Plan a trip's "Remind me when to leave": five minutes before the planned way.
Schedule::command('travel:send-leave-reminders')->everyMinute()->withoutOverlapping(2);
// Its "We keep watching it": a Change of plan when the planned way changes.
Schedule::command('travel:watch-planned-trips')->everyTwoMinutes()->withoutOverlapping(5);

// Weather alerts — every 2 hours, only during commute windows (time-critical)
Schedule::command('weather:check-alerts')->cron('0 */2 * * *')->withoutOverlapping(30);

// Bureaucracy task deadline reminders — daily morning push for urgent/overdue tasks
Schedule::command('bureaucracy:remind')->timezone('Europe/Berlin')->dailyAt('09:00')->withoutOverlapping(60);

// Everything below runs in the background.

// Transit news feed (read by transit:check-disruptions on its next run)
Schedule::command('news:scrape')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
// English briefs for new or changed KVB notices (llm queue; cents a day)
Schedule::command('transit:brief-notices')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Paperwork v2 upkeep: expire processing permits and interaction state, run
// queued erasures and reassessments (reassessment reminders feed the bus).
Schedule::command('bureaucracy:process-erasures')->everyMinute()->withoutOverlapping(5)->onOneServer()->runInBackground();
Schedule::command('bureaucracy:process-reassessments')->everyMinute()->withoutOverlapping(5)->onOneServer()->runInBackground();
Schedule::command('bureaucracy:prune-processing')->everyFiveMinutes()->withoutOverlapping()->onOneServer()->runInBackground();
Schedule::command('bureaucracy:prune-interactions')->everyFiveMinutes()->withoutOverlapping()->onOneServer()->runInBackground();
Schedule::command('bureaucracy:erase-unguarded-dependents')->hourly()->withoutOverlapping()->onOneServer()->runInBackground();
// Re-check every card's source quotes against the official pages. A pass keeps a checked card
// published, a failure withdraws and escalates it; changes ship as a new catalogue release.
Schedule::command('bureaucracy:verify-sources --deploy')->timezone('Europe/Berlin')->dailyAt('05:15')->withoutOverlapping()->onOneServer()->runInBackground();

// Events
Schedule::command('events:scrape')->everyThirtyMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('events:import-official --days=40')->hourly()->withoutOverlapping()->onOneServer()->runInBackground();
Schedule::command('events:enrich')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('events:curate')->hourly()->withoutOverlapping()->runInBackground();
Schedule::command('events:expire')->dailyAt('03:30')->withoutOverlapping()->runInBackground();
Schedule::command('events:import-manual')->weeklyOn(1, '04:00')->withoutOverlapping()->onOneServer()->runInBackground();
Schedule::command('events:backfill-translations --limit=200 --per-minute=30')->hourly()->withoutOverlapping()->onOneServer()->runInBackground();
// Venue links are made inside ProcessEventJob, but a classification failure
// there must never leave events venue-less (and photo-less) forever — this
// sweep catches any orphans the job missed.
Schedule::command('events:link-venues')->hourly()->withoutOverlapping()->onOneServer()->runInBackground();
Schedule::command('events:source-health --json --max-age=36')->everySixHours()->withoutOverlapping()->onOneServer()->runInBackground();

// Places: official boundaries are authoritative; refresh them before the OSM
// catalogue so outside-city rows can never be assigned to a nearby Veedel.
Schedule::command('veedels:import')->monthlyOn(1, '02:00')->withoutOverlapping()->onOneServer()->runInBackground()->when(fn (): bool => (bool) config('places.automation_enabled'));
Schedule::command('spots:assign-veedel --force')->monthlyOn(1, '02:30')->withoutOverlapping()->onOneServer()->runInBackground()->when(fn (): bool => (bool) config('places.automation_enabled'));
// Keep source import, geometry containment, and readable facility names in
// one ordered run. Separate schedules can overlap and leak micro-facilities
// back into Places before their parent destination is assigned.
Schedule::command('app:refresh-places-catalogue')->weeklyOn(1, '03:30')->withoutOverlapping()->onOneServer()->runInBackground()->when(fn (): bool => (bool) config('places.automation_enabled'));

// Rhine flood level — hourly, in-app only (no push)
Schedule::command('rhine:check')->hourly()->withoutOverlapping()->runInBackground();

// Notification health check — hourly automated monitoring
Schedule::command('notification:health-check')->hourly()->withoutOverlapping()->runInBackground();
Schedule::command('location:prune-history')->hourly()->withoutOverlapping()->onOneServer()->runInBackground();

// Restaurant/café scraping is DISABLED for now. v2 de-emphasises the
// restaurant/café layer, and the scraper stores raw OSM opening_hours strings
// (the composer had to be hardened against them). The command still exists for
// manual runs; re-enable here (weekly, not daily) only if that layer comes back
// into scope.
// Schedule::command('restaurants:scrape')->dailyAt('04:00')->withoutOverlapping();

// Resolve and license-check media after Monday's authoritative OSM refresh so
// new and updated place references are enriched in the same ingestion cycle.
Schedule::command('spots:fetch-photos')->weeklyOn(1, '05:30')->withoutOverlapping()->onOneServer()->runInBackground()->when(fn (): bool => (bool) config('places.automation_enabled'));

// Venue photos ride the same weekly cycle, after the events scrapers have
// created any new venues — events inherit these via the media cascade.
Schedule::command('venues:fetch-photos')->weeklyOn(1, '06:00')->withoutOverlapping()->onOneServer()->runInBackground()->when(fn (): bool => (bool) config('places.automation_enabled'));

// Street-level fallback runs LAST, so Commons always gets first refusal and
// Mapillary only fills places no deliberate photograph exists for.
Schedule::command('photos:fetch-mapillary --limit=400')->weeklyOn(1, '06:30')->withoutOverlapping()->onOneServer()->runInBackground()->when(fn (): bool => (bool) config('places.automation_enabled'));
Schedule::command('photos:fetch-mapillary --venues --limit=100')->weeklyOn(1, '07:00')->withoutOverlapping()->onOneServer()->runInBackground()->when(fn (): bool => (bool) config('places.automation_enabled'));

// Independently re-check attached media so expiring URLs and removed files are
// withdrawn even when their acquisition source is not rediscovered that week.
Schedule::command('media:revalidate --limit=200')->hourly()->withoutOverlapping()->onOneServer()->runInBackground()->when(fn (): bool => (bool) config('places.automation_enabled'));

// External API health monitoring — every 5 minutes
Schedule::command('api:health')->everyFiveMinutes()->withoutOverlapping()->onOneServer()->runInBackground();

// GTFS static timetable refresh — VRS updates weekly
Schedule::command('gtfs:refresh')->weeklyOn(1, '03:00')->withoutOverlapping()->runInBackground();

Schedule::command('model:prune')->dailyAt('03:45')->withoutOverlapping()->runInBackground();
// Failed jobs are kept a week for debugging, then dropped.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:50')->withoutOverlapping()->runInBackground();

Schedule::command('controls:synthetic-disruption')->everyThirtyMinutes()->withoutOverlapping();

// Personalisation: decayed affinities and place negatives from the signal ledger.
Schedule::command('signals:aggregate')->dailyAt('04:30')->withoutOverlapping()->onOneServer()->runInBackground();

// Place enrichment batches (places:enrich submits them): apply finished results.
Schedule::command('places:enrich-collect')->everyTenMinutes()->withoutOverlapping()->onOneServer()->runInBackground();

// Model-ready place features, rebuilt from the effective facts each night.
Schedule::command('places:build-features')->dailyAt('04:15')->withoutOverlapping()->onOneServer();
