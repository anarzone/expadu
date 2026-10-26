<?php

use App\ContextEngine\DayBriefs;
use App\ContextEngine\DayImportance;
use App\ContextEngine\PlanRisks;
use App\Models\ComposerWorkspace;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\PlanStopRiskNotification;
use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\NearbyStopService;
use App\Services\WeatherService;
use App\Support\NotificationThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ReviewedHomePlan;

// Phase 2 (coordinator, 26 Oct 2026): what threatens the stops of their plan weighs on the day,
// leads the brief and, under two hours away, pushes once; overdue Paperwork is never a quiet day,
// and pushes once a day at most; their own quiet hours and brief time (phase 3) are read.

uses()->group('context-engine');

/** Wednesday 11 November 2026, Berlin time. */
function riskAt(string $time, string $date = '2026-11-11'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", 'Europe/Berlin');
}

/** A plan for the day: Kolumba (closed on Wednesdays in its hours), then the Rheinpark outdoors. */
function riskPlan(User $user): void
{
    $at = fn (string $t) => riskAt($t)->toIso8601String();
    $slot = fn (string $id, string $name, string $from, string $to, bool $outdoor, array $hours) => [
        'id' => $id, 'type' => 'spot', 'name' => $name, 'category' => $outdoor ? 'park' : 'museum', 'swappable' => true, 'outdoor' => $outdoor,
        'start_time' => $from, 'end_time' => $to, 'start_at' => $at($from), 'end_at' => $at($to), 'lat' => 50.94, 'lng' => 6.95, 'place_facts' => ['hours' => $hours],
    ];
    ComposerWorkspace::query()->updateOrCreate(['user_id' => $user->id], ['revision' => 1, 'state' => [
        'approval' => 'ask', 'active' => 'initial', 'focus' => null, 'draft' => null, 'versions' => [],
        'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]],
        'plans' => ['2026-11-11' => ['date' => '2026-11-11', 'constraints' => ['window_start' => '2026-11-11T08:00:00', 'window_end' => '2026-11-11T23:59:00'], 'slots' => [
            $slot('spot:1', 'Kolumba', '09:00', '10:00', false, ['status' => 'known', 'raw' => 'Th-Su 12:00-17:00', 'parsed' => ['thu' => [['12:00', '17:00']]]]),
            $slot('spot:2', 'Rheinpark', '13:00', '15:00', true, ['status' => 'unknown']),
        ]]],
    ]]);
}

beforeEach(function () {
    Notification::fake();
    $this->travelTo(riskAt('07:00'));
    $this->user = User::factory()->onboarded()->create();
    $this->user->updatePushSubscription('https://push.example.test/'.uniqid(), 'key', 'token');
    // Line 13 is disrupted, it stops near both, nothing's late, and it rains from 14:00.
    app()->instance(DisruptionService::class, new class extends DisruptionService
    {
        public function __construct() {}

        public function getLineDisruptions(): array
        {
            return [['id' => 'news_1', 'source' => 'kvb-betriebslage', 'affected_lines' => ['13'], 'affected_stops' => ['Köln Appellhofplatz'], 'scale' => 'minor', 'severity' => 'major']];
        }
    });
    app()->instance(NearbyStopService::class, new class extends NearbyStopService
    {
        public function __construct() {}

        public function getWalkableStops(float $lat, float $lng, int $radiusMeters = 500): array
        {
            return [['id' => '1', 'name' => 'Appellhofplatz', 'lines' => ['13', '16'], 'lat' => $lat, 'lng' => $lng, 'distance_m' => 120, 'walk_min' => 2]];
        }
    });
    app()->instance(GtfsDepartureService::class, new class extends GtfsDepartureService
    {
        public function __construct() {}

        public function getDeparturesNearby(float $lat, float $lng, int $limit = 10): array
        {
            return ['departures' => [['line' => '16', 'delay' => 2, 'cancelled' => false]]];
        }
    });
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getDayForecast(string $date, float $lat = 50.9375, float $lng = 6.9603): array
        {
            $hour = fn (string $h, float $mm) => ['hour' => $h, 'time' => "{$date}T{$h}:00+01:00", 'precip' => $mm, 'temperature' => 9, 'icon' => $mm > 0 ? 'rain' : 'cloudy'];

            return ['available' => true, 'hours' => [$hour('12:00', 0), $hour('13:00', 0), $hour('14:00', 1.2), $hour('15:00', 0.4)]];
        }
    });
});

test('what threatens their plan weighs on the day and leads the brief: closed, rain while outdoors, a disruption near a stop', function () {
    riskPlan($this->user);

    $day = app(DayImportance::class)->for($this->user, riskAt('00:00'));
    expect($day['risks'])->toEqualCanonicalizing(['closed', 'disruption', 'rain'])
        // Two stops (1 each) twice over.
        ->and($day['score'])->toBe(4.0)->and($day['band'])->toBe(DayImportance::NORMAL);

    expect(array_column(app(DayBriefs::class)->items($this->user, riskAt('00:00'), DayBriefs::MORNING), 'text'))->toBe([
        'Kolumba is listed closed today.',
        'Line 13 disrupted near Kolumba (09:00).',
        'Line 13 disrupted near Rheinpark (13:00).',
        'Rain from 14:00 during Rheinpark (13:00–15:00), outdoors.',
        'Your plan: 2 stops from 09:00, first Kolumba.',
    ]);
});

/** KVB's and others' notices, as DisruptionService gives them, for Kolumba's stop (Appellhofplatz: lines 13 and 16). */
function riskNotices(array $notices): void
{
    app()->instance(DisruptionService::class, new class($notices) extends DisruptionService
    {
        public function __construct(private array $notices) {}

        public function getLineDisruptions(): array
        {
            return array_map(fn (array $n): array => ['id' => $n[0], 'source' => $n[1], 'affected_lines' => [$n[2]], 'scale' => $n[3], 'affected_stops' => $n[4]], $this->notices);
        }
    });
}

/** @return list<array{0: string, 1: string}> Kolumba's disruptions: text and severity */
function kolumbaDisruptions(User $user): array
{
    return collect(app(PlanRisks::class)->for($user, riskAt('00:00')))->where('slot', 'spot:1')->where('kind', 'disruption')
        ->map(fn (array $r): array => [$r['text'], $r['severity']])->values()->all();
}

test('VRS’s and DELFI’s notices show on boards and are never a risk to the plan', function () {
    riskPlan($this->user);
    riskNotices([['news_2', 'delfi', '13', 'major', []], ['news_3', 'vrs-trias', '16', 'major', ['Köln Appellhofplatz']]]);

    expect(kolumbaDisruptions($this->user))->toBe([]);
});

test('an everyday notice counts only where it is; a major one on the line counts anywhere', function () {
    riskPlan($this->user);
    riskNotices([
        // Bus works at Bocklemünd say nothing of a stop at Appellhofplatz.
        ['news_4', 'kvb-betriebslage', '16', 'minor', ['Köln Bocklemünd', 'Köln Schwindstr.']],
        ['news_5', 'kvb-betriebslage', '13', 'minor', ['Köln Appellhofplatz']],
    ]);
    expect(kolumbaDisruptions($this->user))->toBe([['Line 13 disrupted near Kolumba (09:00).', 'moderate']]);

    riskNotices([['news_6', 'kvb-betriebslage', '16', 'major', []]]);
    expect(kolumbaDisruptions($this->user))->toBe([['Line 16 disrupted near Kolumba (09:00).', 'critical']]);
});

test('each line is one risk, at its worst, however many notices name it', function () {
    riskPlan($this->user);
    riskNotices([
        ['news_5', 'kvb-betriebslage', '13', 'minor', ['Köln Appellhofplatz']],
        ['news_6', 'kvb-betriebslage', '13', 'major', []],
        ['news_7', 'kvb-betriebslage', '13', 'minor', ['Köln Appellhofplatz']],
    ]);

    expect(kolumbaDisruptions($this->user))->toBe([['Line 13 disrupted near Kolumba (09:00).', 'critical']]);
});

test('a stop’s risks push once, together, only when it starts within two hours', function () {
    riskPlan($this->user);

    // 06:59: Kolumba at 09:00 is more than two hours off.
    $this->travelTo(riskAt('06:59'));
    $this->artisan('notifications:watch-plans')->assertSuccessful();
    Notification::assertNothingSent();

    // 07:00: Kolumba's two, as one push; the Rheinpark isn't near yet.
    $this->travelTo(riskAt('07:00'));
    $this->artisan('notifications:watch-plans')->assertSuccessful();
    $this->artisan('notifications:watch-plans')->assertSuccessful();
    Notification::assertSentToTimes($this->user, PlanStopRiskNotification::class, 1);
    Notification::assertSentTo($this->user, PlanStopRiskNotification::class, fn (PlanStopRiskNotification $n) => $n->toArray($this->user) === [
        'type' => 'plan_risk', 'title' => 'Kolumba is listed closed today.', 'body' => 'Line 13 disrupted near Kolumba (09:00). In your plan at 09:00.', 'url' => '/']);
});

test('overdue Paperwork makes the day at least normal, leads the brief, and pushes once a day at most', function () {
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']], ['arrival_date' => '2026-06-01', 'visa_expires_at' => '2026-11-10']);

    $day = app(DayImportance::class)->for($this->user, riskAt('00:00'));
    expect(collect($day['commitments'])->pluck('kind')->all())->toContain('paperwork_urgent')
        ->and($day['band'])->not->toBe(DayImportance::QUIET)
        ->and(app(DayBriefs::class)->items($this->user, riskAt('00:00'), DayBriefs::MORNING)[0]['text'])->toBe('Your Paperwork needs attention.');

    $this->travelTo(riskAt('10:00'));
    expect(NotificationThrottle::decide($this->user, 'bureaucracy_task')->send)->toBeTrue();
    NotificationThrottle::recordSent($this->user, 'bureaucracy_task');
    $this->travelTo(riskAt('15:00'));
    expect(NotificationThrottle::decide($this->user, 'bureaucracy_task')->reason)->toBe('paperwork_today');
    $this->travelTo(riskAt('10:00', '2026-11-12'));
    expect(NotificationThrottle::decide($this->user, 'bureaucracy_task')->send)->toBeTrue();
});

test('their own quiet hours and brief time count once Settings has them', function () {
    $this->user->setRelation('notificationPreference', (new NotificationPreference)->forceFill(['quiet_from' => '23:30:00', 'quiet_to' => '07:30:00', 'brief_at' => '08:15:00']));

    $this->travelTo(riskAt('22:30'));
    expect(NotificationThrottle::decide($this->user, 'transit_disruption', 'major')->reason)->not->toBe('quiet_hours');
    $this->travelTo(riskAt('07:00'));
    expect(NotificationThrottle::decide($this->user, 'transit_disruption', 'major')->reason)->toBe('quiet_hours')
        ->and(NotificationThrottle::decide($this->user, 'event_reminder', 'moderate', riskAt('12:00'))->retryAt?->format('H:i'))->toBe('07:30')
        ->and(app(DayBriefs::class)->morningAt($this->user, riskAt('00:00'))->format('H:i'))->toBe('08:15');
});
