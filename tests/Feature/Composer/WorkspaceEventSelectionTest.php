<?php

use App\Composer\CandidateRepository;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('selected occurrences resolve independently of the nearest fifty discovery limit', function () {
    config(['events.geocoding.expected_polygon_count' => 1]);
    DB::table('veedels')->insert(['name' => 'Workspace Boundary', 'bezirk' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    DB::statement("UPDATE veedels SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326)) WHERE name = 'Workspace Boundary'");
    $day = CarbonImmutable::now('Europe/Berlin')->addDay()->startOfDay();
    $organiser = User::factory()->create();
    Event::factory()->count(51)->create(['organiser_id' => $organiser->id, 'starts_at' => $day->setTime(15, 0), 'ends_at' => $day->setTime(16, 0)]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(6.9603, 50.9375), 4326)::geography');
    $selected = Event::factory()->create(['organiser_id' => $organiser->id, 'starts_at' => $day->setTime(15, 0), 'ends_at' => $day->setTime(16, 0)]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(6.99, 50.99), 4326)::geography WHERE id = ?', [$selected->id]);
    $result = app(CandidateRepository::class)->byIds(["event:{$selected->id}"], $day);
    expect($result)->toHaveCount(1)->and($result[0]->id)->toBe("event:{$selected->id}");
    $this->actingAs(User::factory()->onboarded()->create())->postJson('/composer/workspace', ['revision' => 0, 'action' => 'add', 'date' => $day->format('Y-m-d'), 'candidate' => "event:{$selected->id}"])->assertOk()->assertJsonPath("plans.{$day->format('Y-m-d')}.slots.0.start_time", '15:00');
});

test('selected fifteen minute events keep their listed end time in the plan', function () {
    config(['events.geocoding.expected_polygon_count' => 1]);
    DB::table('veedels')->insert(['name' => 'Workspace Boundary', 'bezirk' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    DB::statement("UPDATE veedels SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326)) WHERE name = 'Workspace Boundary'");
    $day = CarbonImmutable::now('Europe/Berlin')->addDay()->startOfDay();
    $event = Event::factory()->create([
        'starts_at' => $day->setTime(18, 0),
        'ends_at' => $day->setTime(18, 15),
    ]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(6.9603, 50.9375), 4326)::geography WHERE id = ?', [$event->id]);

    $candidates = app(CandidateRepository::class)->byIds(["event:{$event->id}"], $day);
    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->typicalDurationMin)->toBe(15);

    $this->actingAs(User::factory()->onboarded()->create())
        ->postJson('/composer/workspace', [
            'revision' => 0,
            'action' => 'add',
            'date' => $day->format('Y-m-d'),
            'candidate' => "event:{$event->id}",
        ])
        ->assertSuccessful()
        ->assertJsonPath("plans.{$day->format('Y-m-d')}.date", $day->format('Y-m-d'))
        ->assertJsonPath("plans.{$day->format('Y-m-d')}.slots.0.start_time", '18:00')
        ->assertJsonPath("plans.{$day->format('Y-m-d')}.slots.0.end_time", '18:15');
});
