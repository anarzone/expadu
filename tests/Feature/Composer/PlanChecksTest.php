<?php

use App\Composer\Eval\PlanChecks;
use App\Models\Spot;
use Carbon\CarbonImmutable;

function evalSlot(Spot $spot, string $start, string $end, array $extra = []): array
{
    return [
        'id' => "spot:{$spot->id}", 'type' => 'spot', 'name' => $spot->name, 'category' => $spot->category->value,
        'lat' => (float) $spot->lat, 'lng' => (float) $spot->lng, 'start_at' => "2026-10-31T{$start}:00+01:00",
        'end_at' => "2026-10-31T{$end}:00+01:00", 'travel_min_from_previous' => 0, 'hours_assumed' => false, ...$extra,
    ];
}

beforeEach(function () {
    $this->window = [CarbonImmutable::parse('2026-10-31T12:00:00+01:00'), CarbonImmutable::parse('2026-10-31T19:00:00+01:00')];
    $this->spot = Spot::factory()->create(['name' => 'Café Lichtblick', 'category' => 'cafe', 'lat' => 50.94, 'lng' => 6.92]);
});

it('passes a plan whose stops match their rows and fit the day', function () {
    $other = Spot::factory()->create(['category' => 'park', 'lat' => 50.941, 'lng' => 6.921]);

    $result = app(PlanChecks::class)->gates([
        evalSlot($this->spot, '13:00', '14:00', ['closes_at' => '18:00']),
        evalSlot($other, '14:15', '15:30', ['travel_min_from_previous' => 12]),
    ], ...$this->window);

    expect(array_merge(...array_values($result['gates'])))->toBe([]);
});

it('fails a stop the database does not know', function () {
    $slot = evalSlot($this->spot, '13:00', '14:00', ['id' => 'spot:999999']);

    expect(app(PlanChecks::class)->gates([$slot], ...$this->window)['gates']['unknown_id'])->toHaveCount(1);
});

it('fails a stop whose name or place differs from its row', function () {
    $slot = evalSlot($this->spot, '13:00', '14:00', ['name' => 'Café Invented', 'lat' => 50.99]);

    expect(app(PlanChecks::class)->gates([$slot], ...$this->window)['gates']['fact_mismatch'])->toHaveCount(2);
});

it('fails a plan you could not follow', function (array $first, array $second, string $why) {
    $slots = [evalSlot($this->spot, ...$first), evalSlot($this->spot, ...$second)];

    expect(app(PlanChecks::class)->gates($slots, ...$this->window)['gates']['infeasible'])
        ->toHaveCount(1)
        ->and(implode(' ', app(PlanChecks::class)->gates($slots, ...$this->window)['gates']['infeasible']))->toContain($why);
})->with([
    'overlapping stops' => [['13:00', '14:00'], ['13:30', '14:30'], 'before the previous stop ends'],
    'no time to travel' => [['13:00', '14:00'], ['14:05', '15:00', ['travel_min_from_previous' => 20]], 'before the previous stop ends'],
    'past closing time' => [['13:00', '14:00'], ['17:30', '18:45', ['closes_at' => '18:00']], 'after it closes'],
    'outside the window' => [['13:00', '14:00'], ['18:30', '19:30'], 'outside the window'],
]);
