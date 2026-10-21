<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Models\ComposerWorkspace;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPlace;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A router that answers from fixtures: arrive-by searches and depart-at
 * searches each get their own list, and every call is recorded.
 */
class FakeDayRoutes implements RouteService
{
    /** @var list<Journey> */
    public array $arriveBy = [];

    /** @var list<Journey> */
    public array $departAt = [];

    public bool $degraded = false;

    /** @var list<array{from: GeoPoint, to: GeoPoint, at: ?CarbonImmutable, arriveBy: bool}> */
    public array $calls = [];

    public ?int $walkMinutes = null;

    public function withTimeBudget(float $seconds): static
    {
        return $this;
    }

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false, bool $variety = false): JourneyResult
    {
        $this->calls[] = ['from' => $from, 'to' => $to, 'at' => $departAt, 'arriveBy' => $arriveBy];
        if ($this->degraded) {
            return new JourneyResult([], 'degraded', ['departures' => [], 'deep_links' => []]);
        }

        return new JourneyResult($arriveBy ? $this->arriveBy : $this->departAt, 'motis');
    }

    public function geocode(string $query, ?GeoPoint $bias = null): array
    {
        return [];
    }

    public function reverseGeocode(GeoPoint $point): ?Place
    {
        return null;
    }

    public function travelMatrix(GeoPoint $origin, array $destinations, string $mode = 'BIKE'): array
    {
        return array_fill(0, count($destinations), $this->walkMinutes);
    }
}

function dayRoutes(): FakeDayRoutes
{
    $routes = new FakeDayRoutes;
    app()->instance(RouteService::class, $routes);

    return $routes;
}

/** A tram ride between two Berlin wall-clock times on $date. */
function ride(string $date, string $leave, string $arrive, bool $realtime = false, bool $cancelled = false): Journey
{
    $depart = CarbonImmutable::parse("{$date} {$leave}", 'Europe/Berlin');
    $arrival = CarbonImmutable::parse("{$date} {$arrive}", 'Europe/Berlin');
    if ($arrival->lessThan($depart)) {
        $arrival = $arrival->addDay();
    }
    $from = new Place('Neumarkt', new GeoPoint(50.9363, 6.9469));
    $to = new Place('Wiener Platz', new GeoPoint(50.9610, 7.0048));
    $minutes = (int) (($arrival->getTimestamp() - $depart->getTimestamp()) / 60);

    return new Journey([
        new Leg('tram', $from, $to, $depart, $arrival, $minutes, '4', 'Schlebusch', realtime: $realtime, cancelled: $cancelled),
    ], $depart, $arrival, $minutes, 0);
}

/**
 * A Paperwork v2 appointment the person recorded on their own plan: its
 * place (a label alone, or with coordinates) and its length, when known.
 */
function recordedAppointment(User $user, string $at, array $location, ?int $duration = null, string $title = 'Day appointment'): void
{
    $case = app(EnsureAccountHolder::class)->dossier($user);
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.day_appointment', 'title' => $title,
        'applies_if' => [], 'deadline_type' => 'none', 'depends_on' => [], 'type' => 'task',
        'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
        'process_id' => 'fixture.day_appointment', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]))->id, null);
    $process = app(ReconcileProcesses::class)->execute($user, $case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($user, $process, 'appointment_recorded', [
        'appointment_id' => (string) Str::uuid(), 'starts_at' => CarbonImmutable::parse($at, 'Europe/Berlin')->toIso8601String(),
        'timezone' => 'Europe/Berlin', 'duration_minutes' => $duration, 'location' => $location,
    ], 1, (string) Str::uuid());
}

/** One confirmed Composer day with the given slots. */
function confirmedDay(User $user, string $date, array $slots, int $revision = 1): ComposerWorkspace
{
    return ComposerWorkspace::query()->create([
        'user_id' => $user->id,
        'revision' => $revision,
        'state' => [
            'approval' => 'ask', 'active' => 'initial', 'focus' => null, 'draft' => null, 'versions' => [],
            'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]],
            'plans' => [$date => ['date' => $date, 'slots' => $slots, 'constraints' => ['window_start' => "{$date}T08:00:00", 'window_end' => "{$date}T23:59:00"]]],
        ],
    ]);
}

function personalSlot(string $id, string $date, string $start, string $end, array $extra = []): array
{
    $at = fn (string $time) => CarbonImmutable::parse("{$date} {$time}", 'Europe/Berlin')->toIso8601String();

    return array_replace(['id' => $id, 'type' => 'personal', 'name' => 'Plan '.$id, 'category' => 'appointment', 'swappable' => false,
        'start_time' => $start, 'end_time' => $end, 'start_at' => $at($start), 'end_at' => $at($end)], $extra);
}

const WIENER_PLATZ = ['lat' => 50.96103, 'lng' => 7.0048];

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 13:10', 'Europe/Berlin'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the day is for signed-in people only', function () {
    $this->getJson('/api/day')->assertUnauthorized();
});

test('one account never reads another account\'s day', function () {
    dayRoutes();
    $a = User::factory()->onboarded()->create();
    $b = User::factory()->onboarded()->create();
    confirmedDay($b, '2026-10-19', [personalSlot('personal:b-plan', '2026-10-19', '15:00', '16:00', ['name' => 'B private dentist'] + WIENER_PLATZ)]);
    recordedAppointment($b, '2026-10-19 14:00', ['label' => 'B office', 'lat' => 50.9416, 'lng' => 7.0009], 30, 'B Blue Card');
    $bHome = UserPlace::factory()->create(['user_id' => $b->id, 'category' => 'home', 'name' => 'B home', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($a)->getJson('/api/day?date=2026-10-19&from_place='.$bHome->id)
        ->assertOk()
        ->assertJsonPath('items', [])
        ->assertJsonPath('next_trip', null)
        ->assertJsonPath('home', null)
        ->assertJsonPath('revision', 0)
        ->assertDontSee('B private dentist')
        ->assertDontSee('B Blue Card')
        ->assertDontSee('B home');

    $this->actingAs($b)->getJson('/api/day?date=2026-10-19')
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertSee('B private dentist')
        ->assertSee('B Blue Card');
});

test('another account\'s saved place is never used as the starting point', function () {
    $routes = dayRoutes();
    $a = User::factory()->onboarded()->create();
    $b = User::factory()->onboarded()->create();
    confirmedDay($a, '2026-10-19', [personalSlot('personal:a', '2026-10-19', '15:00', '16:00', WIENER_PLATZ)]);
    $bHome = UserPlace::factory()->create(['user_id' => $b->id, 'category' => 'home', 'lat' => 50.95, 'lng' => 6.92]);

    $this->actingAs($a)->getJson('/api/day?date=2026-10-19&from_place='.$bHome->id)
        ->assertOk()
        ->assertJsonPath('next_trip.status', 'unavailable')
        ->assertJsonPath('next_trip.reason', 'location_unknown');
    expect($routes->calls)->toBeEmpty();
});

test('mark as done only ever changes the signed-in account\'s own plan', function () {
    dayRoutes();
    $a = User::factory()->onboarded()->create();
    $b = User::factory()->onboarded()->create();
    confirmedDay($a, '2026-10-19', [personalSlot('personal:a', '2026-10-19', '09:00', '10:00')]);
    $bRow = confirmedDay($b, '2026-10-19', [personalSlot('personal:b', '2026-10-19', '09:00', '10:00')]);

    // B's slot through A's account: A has no such slot, and B's day is untouched.
    $this->actingAs($a)->postJson('/composer/workspace', ['revision' => 1, 'action' => 'complete', 'id' => 'personal:b', 'date' => '2026-10-19'])
        ->assertUnprocessable();
    expect($bRow->fresh()->state['plans']['2026-10-19']['slots'][0])->not->toHaveKey('completed_at')
        ->and($bRow->fresh()->revision)->toBe(1);

    $this->actingAs($a)->postJson('/composer/workspace', ['revision' => 1, 'action' => 'complete', 'id' => 'personal:a', 'date' => '2026-10-19'])
        ->assertOk()->assertJsonPath('revision', 2);
    $this->actingAs($a)->getJson('/api/day?date=2026-10-19')
        ->assertJsonPath('items.0.completed', true)
        ->assertJsonPath('items.0.completed_at', '2026-10-19T13:10:00+02:00')
        ->assertJsonPath('revision', 2);
    $this->actingAs($b)->getJson('/api/day?date=2026-10-19')->assertJsonPath('items.0.completed', false);

    // A stale tab can't overwrite, and a day without the plan says so.
    $this->actingAs($a)->postJson('/composer/workspace', ['revision' => 1, 'action' => 'uncomplete', 'id' => 'personal:a', 'date' => '2026-10-19'])->assertConflict();
    $this->actingAs($a)->postJson('/composer/workspace', ['revision' => 2, 'action' => 'uncomplete', 'id' => 'personal:a', 'date' => '2026-10-20'])->assertUnprocessable();
    $this->actingAs($a)->postJson('/composer/workspace', ['revision' => 2, 'action' => 'complete', 'date' => '2026-10-19'])->assertUnprocessable();

    $this->actingAs($a)->postJson('/composer/workspace', ['revision' => 2, 'action' => 'uncomplete', 'id' => 'personal:a', 'date' => '2026-10-19'])->assertOk();
    $this->actingAs($a)->getJson('/api/day?date=2026-10-19')->assertJsonPath('items.0.completed', false);
});

test('an official appointment takes the earlier tram when the later one leaves under ten minutes', function () {
    $routes = dayRoutes();
    $routes->arriveBy = [ride('2026-10-19', '13:24', '13:52'), ride('2026-10-19', '13:14', '13:42')];
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-19', [personalSlot('personal:x', '2026-10-19', '14:00', '14:30', ['type' => 'appointment', 'id' => 'appointment:7'] + WIENER_PLATZ)]);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-19&lat=50.9363&lng=6.9469')
        ->assertOk()
        ->assertJsonPath('next_trip.status', 'fits')
        ->assertJsonPath('next_trip.reason', 'earlier_for_margin')
        ->assertJsonPath('next_trip.margin_min', 10)
        ->assertJsonPath('next_trip.leave_time', '13:14')
        ->assertJsonPath('next_trip.leave_in_min', 4)
        ->assertJsonPath('next_trip.spare_min', 18)
        ->assertJsonPath('next_trip.skipped.leave_time', '13:24')
        ->assertJsonPath('next_trip.skipped.spare_min', 8)
        ->assertJsonPath('next_trip.skipped.status', 'tight')
        ->assertJsonPath('next_trip.skipped.ride.time', '13:24')
        ->assertJsonPath('next_trip.skipped.ride.line', '4')
        ->assertJsonPath('next_trip.time_basis', 'scheduled')
        ->assertJsonPath('next_trip.from.source', 'live');

    expect($routes->calls[0]['arriveBy'])->toBeTrue()
        ->and($routes->calls[0]['at']->format('H:i'))->toBe('14:00');
});

test('other plans keep five minutes, so the later tram fits', function () {
    $routes = dayRoutes();
    $routes->arriveBy = [ride('2026-10-19', '13:24', '13:52', realtime: true), ride('2026-10-19', '13:14', '13:42')];
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-19', [personalSlot('personal:x', '2026-10-19', '14:00', '14:30', WIENER_PLATZ)]);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-19&lat=50.9363&lng=6.9469')
        ->assertJsonPath('next_trip.status', 'fits')
        ->assertJsonPath('next_trip.reason', 'margin_met')
        ->assertJsonPath('next_trip.margin_min', 5)
        ->assertJsonPath('next_trip.leave_time', '13:24')
        ->assertJsonPath('next_trip.spare_min', 8)
        ->assertJsonPath('next_trip.time_basis', 'live')
        ->assertJsonPath('next_trip.skipped', null)
        ->assertJsonPath('next_trip.alternatives.0.leave_time', '13:14');
});

test('a trip inside the margin is tight, a cancelled one is never chosen', function () {
    $routes = dayRoutes();
    $routes->arriveBy = [ride('2026-10-19', '13:30', '13:57'), ride('2026-10-19', '13:20', '13:45', cancelled: true)];
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-19', [personalSlot('personal:x', '2026-10-19', '14:00', '14:30', ['type' => 'appointment', 'id' => 'appointment:7'] + WIENER_PLATZ)]);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-19&lat=50.9363&lng=6.9469')
        ->assertJsonPath('next_trip.status', 'tight')
        ->assertJsonPath('next_trip.reason', 'only_tight')
        ->assertJsonPath('next_trip.leave_time', '13:30')
        ->assertJsonPath('next_trip.spare_min', 3)
        ->assertJsonPath('next_trip.alternatives.0.status', 'cancelled');
});

test('when nothing arrives in time the earliest arrival says how late', function () {
    $routes = dayRoutes();
    // Every arrive-by option has already left.
    $routes->arriveBy = [ride('2026-10-19', '13:00', '13:28')];
    $routes->departAt = [ride('2026-10-19', '13:40', '14:07'), ride('2026-10-19', '13:50', '14:17')];
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-19', [personalSlot('personal:x', '2026-10-19', '14:00', '14:30', WIENER_PLATZ)]);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-19&lat=50.9363&lng=6.9469')
        ->assertJsonPath('next_trip.status', 'late')
        ->assertJsonPath('next_trip.reason', 'no_connection_in_time')
        ->assertJsonPath('next_trip.leave_time', '13:40')
        ->assertJsonPath('next_trip.late_by_min', 7);
});

test('the trip says what is missing instead of guessing', function () {
    $routes = dayRoutes();
    $user = User::factory()->onboarded()->create();
    // An appointment recorded with a text-only place and no length: no
    // office is located and no end is guessed.
    recordedAppointment($user, '2026-10-19 14:00', ['label' => 'Ausländerbehörde']);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-19&lat=50.9363&lng=6.9469')
        ->assertJsonPath('items.0.kind', 'appointment')
        ->assertJsonPath('items.0.source', 'paperwork')
        ->assertJsonPath('items.0.official', true)
        ->assertJsonPath('items.0.location.label', 'Ausländerbehörde')
        ->assertJsonPath('items.0.location.routable', false)
        ->assertJsonPath('items.0.duration_known', false)
        ->assertJsonPath('items.0.end_at', null)
        ->assertJsonPath('next_trip.status', 'unavailable')
        ->assertJsonPath('next_trip.reason', 'destination_unknown');

    // Without a known starting point there is no trip either.
    $other = User::factory()->onboarded()->create();
    confirmedDay($other, '2026-10-19', [personalSlot('personal:x', '2026-10-19', '15:00', '16:00', WIENER_PLATZ)]);
    $this->actingAs($other)->getJson('/api/day?date=2026-10-19')
        ->assertJsonPath('next_trip.reason', 'location_unknown');

    // And a router that can't answer reads unavailable, not a made-up time.
    $routes->degraded = true;
    $this->actingAs($other)->getJson('/api/day?date=2026-10-19&lat=50.9363&lng=6.9469')
        ->assertJsonPath('next_trip.status', 'unavailable')
        ->assertJsonPath('next_trip.reason', 'routing_unavailable')
        ->assertJsonPath('next_trip.leave_at', null);
    expect($routes->calls)->toHaveCount(1);
});

test('the trip starts from the plan you are at, once it ends', function () {
    $routes = dayRoutes();
    $routes->arriveBy = [ride('2026-10-19', '15:20', '15:50')];
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-19', [
        personalSlot('personal:now', '2026-10-19', '13:00', '15:00', ['name' => 'Lunch', 'location' => 'Neumarkt', 'lat' => 50.9363, 'lng' => 6.9469]),
        personalSlot('personal:next', '2026-10-19', '16:00', '17:00', WIENER_PLATZ),
    ]);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-19&lat=50.94&lng=6.95')
        ->assertJsonPath('items.0.state', 'ongoing')
        ->assertJsonPath('next_trip.target.id', 'personal:next')
        ->assertJsonPath('next_trip.from.source', 'plan')
        ->assertJsonPath('next_trip.from.label', 'Neumarkt')
        ->assertJsonPath('next_trip.from.not_before', '2026-10-19T15:00:00+02:00')
        ->assertJsonPath('next_trip.leave_time', '15:20');
    expect($routes->calls[0]['from']->lat)->toBe(50.9363);
});

test('the day after summer time ends has 25 hours and times keep their offsets', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 09:00', 'Europe/Berlin'));
    $routes = dayRoutes();
    $routes->arriveBy = [ride('2026-10-25', '09:20', '09:48')];
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-25', [personalSlot('personal:x', '2026-10-25', '10:00', '11:00', WIENER_PLATZ)]);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-25&lat=50.9363&lng=6.9469')
        ->assertJsonPath('hours_in_day', 25)
        ->assertJsonPath('day_start', '2026-10-25T00:00:00+02:00')
        ->assertJsonPath('day_end', '2026-10-26T00:00:00+01:00')
        ->assertJsonPath('utc_offset', '+01:00')
        ->assertJsonPath('items.0.start_at', '2026-10-25T10:00:00+01:00')
        ->assertJsonPath('next_trip.leave_at', '2026-10-25T09:20:00+01:00')
        ->assertJsonPath('next_trip.leave_in_min', 20);
});

test('late at night the next trip looks past midnight', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 23:40', 'Europe/Berlin'));
    $routes = dayRoutes();
    $routes->arriveBy = [ride('2026-10-24', '23:55', '00:20')];
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-25', [personalSlot('personal:late', '2026-10-25', '00:30', '02:00', WIENER_PLATZ)]);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-24&lat=50.9363&lng=6.9469')
        ->assertJsonPath('items', [])
        ->assertJsonPath('next_trip.target.id', 'personal:late')
        ->assertJsonPath('next_trip.leave_at', '2026-10-24T23:55:00+02:00')
        ->assertJsonPath('next_trip.spare_min', 10)
        ->assertJsonPath('refresh_at', '2026-10-24T23:45:00+02:00');

    // A quiet evening re-reads at midnight, when the day turns over.
    $quiet = User::factory()->onboarded()->create();
    $this->actingAs($quiet)->getJson('/api/day?date=2026-10-24')
        ->assertJsonPath('next_trip', null)
        ->assertJsonPath('refresh_at', '2026-10-25T00:00:00+02:00');
});

test('past and future days carry no next trip and reject bad dates', function () {
    dayRoutes();
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-18', [personalSlot('personal:x', '2026-10-18', '14:00', '15:00', WIENER_PLATZ)]);

    $this->actingAs($user)->getJson('/api/day?date=2026-10-18')
        ->assertJsonPath('relation', 'past')
        ->assertJsonPath('items.0.state', 'elapsed')
        ->assertJsonPath('next_trip', null)
        ->assertJsonPath('refresh_at', null);
    $this->actingAs($user)->getJson('/api/day?date=2026-10-30')->assertJsonPath('relation', 'future')->assertJsonPath('next_trip', null);
    $this->actingAs($user)->getJson('/api/day?date=19.10.2026')->assertUnprocessable();
});

test('trips between plans are checked or say they were not', function () {
    $routes = dayRoutes();
    $routes->walkMinutes = 12;
    $user = User::factory()->onboarded()->create();
    confirmedDay($user, '2026-10-19', [
        personalSlot('personal:a', '2026-10-19', '15:00', '16:00', ['lat' => 50.9363, 'lng' => 6.9469]),
        personalSlot('personal:b', '2026-10-19', '16:30', '17:00', WIENER_PLATZ),
        personalSlot('personal:c', '2026-10-19', '17:30', '18:00', ['location' => 'Somewhere I typed']),
        personalSlot('personal:d', '2026-10-19', '17:45', '18:30', WIENER_PLATZ),
    ]);

    $links = $this->actingAs($user)->getJson('/api/day?date=2026-10-19')->json('links');

    expect($links)->toHaveCount(3)
        ->and($links[0])->toMatchArray(['from_id' => 'personal:a', 'to_id' => 'personal:b', 'gap_min' => 30, 'travel_min' => 12, 'basis' => 'walk', 'status' => 'ok'])
        ->and($links[1])->toMatchArray(['status' => 'not_checked', 'reason' => 'location_unknown', 'travel_min' => null])
        ->and($links[2])->toMatchArray(['status' => 'conflict', 'reason' => 'overlap']);
});

test('a recorded Paperwork appointment is an official plan with its own place and length, and its trip', function () {
    $routes = dayRoutes();
    $routes->arriveBy = [ride('2026-10-19', '13:14', '13:42')];
    $user = User::factory()->onboarded()->create();
    recordedAppointment($user, '2026-10-19 14:00', ['label' => 'Mülheim', 'lat' => 50.96103, 'lng' => 7.0048], 30, 'EU Blue Card');

    $this->actingAs($user)->getJson('/api/day?date=2026-10-19&lat=50.9363&lng=6.9469')
        ->assertJsonPath('items.0.source', 'paperwork')
        ->assertJsonPath('items.0.title', 'EU Blue Card appointment')
        ->assertJsonPath('items.0.official', true)
        ->assertJsonPath('items.0.start_time', '14:00')
        ->assertJsonPath('items.0.end_time', '14:30')
        ->assertJsonPath('items.0.location.label', 'Mülheim')
        ->assertJsonPath('items.0.location.routable', true)
        ->assertJsonPath('next_trip.target.id', fn (string $id) => str_starts_with($id, 'appointment:'))
        ->assertJsonPath('next_trip.margin_min', 10)
        ->assertJsonPath('next_trip.leave_time', '13:14');
});
