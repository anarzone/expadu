<?php

use App\Day\DayLine;
use App\Models\Event;
use App\Models\PlannedTrip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
 * Today's one line under the date (track, week 2): the day said from what's on it, in the brief's
 * own words, written in code, never by the AI. Its times come marked for the phone to set in bold.
 */

beforeEach(function () {
    Notification::fake();
    // Tuesday 17 November 2026, 07:30.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 07:30', 'Europe/Berlin'));
    $this->user = User::factory()->create();
});

function dayLineTrip(User $user, string $departs, string $title): void
{
    $at = CarbonImmutable::parse($departs, 'Europe/Berlin');
    PlannedTrip::query()->create(['user_id' => $user->id, 'date' => $at->toDateString(), 'target_id' => 'plan-'.uniqid(), 'title' => $title,
        'journey' => [], 'remind' => false, 'depart_at' => $at, 'arrive_at' => $at->addMinutes(30)]);
}

test('a day with something on says it in the brief\'s words, its time marked', function () {
    dayLineTrip($this->user, '2026-11-17 08:12', 'Bürgeramt Kalk');

    $line = app(DayLine::class)->for($this->user, CarbonImmutable::now());

    expect($line['text'])->toContain('Bürgeramt Kalk')->toContain('08:12')
        ->and(collect($line['parts'])->firstWhere('strong', true)['text'])->toBe('08:12');
});

test('a busy day says so, with how many more', function () {
    dayLineTrip($this->user, '2026-11-17 08:12', 'Bürgeramt Kalk');
    dayLineTrip($this->user, '2026-11-17 12:00', 'Lunch with Aylin');
    dayLineTrip($this->user, '2026-11-17 19:30', 'Jazz im Stadtgarten');

    $text = app(DayLine::class)->for($this->user, CarbonImmutable::now())['text'];

    expect($text)->toStartWith('A busy day. ')->toContain('Bürgeramt Kalk')->toEndWith('Plus 2 more.');
});

test('a free day says what\'s on in the city instead, and the phone gets it with Today', function () {
    $as = $this->withToken($this->user->createToken('iPhone')->plainTextToken);

    $line = $as->getJson('/api/v1/today')->assertOk()->json('day_line');

    expect($line['text'])->toStartWith('You have nothing planned');
});

test('a free day names when the city\'s next event starts, never how many: Today\'s events box counts those', function () {
    Event::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-17 09:30', 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse('2026-11-17 11:00', 'Europe/Berlin')]);
    Event::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-17 14:00', 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse('2026-11-17 16:00', 'Europe/Berlin')]);

    expect(app(DayLine::class)->for($this->user, CarbonImmutable::now())['text'])->toBe('You have nothing planned. The next event in Cologne starts at 09:30.');

    // Later in the day, the next one from then.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Berlin'));
    expect(app(DayLine::class)->for($this->user, CarbonImmutable::now())['text'])->toBe('You have nothing planned. The next event in Cologne starts at 14:00.');
});

test('a risk to a stop of their plan names the stop, so the plan isn\'t said again after it', function () {
    $rain = ['kind' => 'plan_rain', 'text' => 'Rain from 18:00 during Stadtgarten walk (17:30–18:30), outdoors.'];
    $plan = ['kind' => 'plan', 'text' => 'Your plan: 1 stop from 17:30, first Stadtgarten walk.'];
    $trip = ['kind' => 'trip', 'text' => '08:12 leave for Bürgeramt Kalk.'];

    expect(DayLine::said([$rain, $trip, $plan]))->toBe([$rain, $trip])
        // With nothing at risk, the plan is said.
        ->and(DayLine::said([$trip, $plan]))->toBe([$trip, $plan]);
});

test('a line is cut into pieces with each time its own', function () {
    expect(DayLine::parts('Rain from 16:00. Your walk at 17:30 is outdoors.'))->toBe([
        ['text' => 'Rain from ', 'strong' => false], ['text' => '16:00', 'strong' => true], ['text' => '. Your walk at ', 'strong' => false],
        ['text' => '17:30', 'strong' => true], ['text' => ' is outdoors.', 'strong' => false],
    ]);
});
