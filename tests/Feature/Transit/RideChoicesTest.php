<?php

use App\Transit\RideChoices;
use App\Transit\TransitousAdapter;
use Carbon\CarbonImmutable;

/** A leg as the planner sends it (Journey::toArray), times on 13 Nov 2026. */
function choiceLeg(string $mode, ?string $line, string $from, string $to, string $depart, string $arrive): array
{
    $at = fn (string $time) => "2026-11-13T{$time}:00+01:00";
    $minutes = (int) ((strtotime($at($arrive)) - strtotime($at($depart))) / 60);

    return [
        'mode' => $mode, 'line' => $line,
        'from' => ['name' => $from, 'lat' => 50.9, 'lng' => 6.9], 'to' => ['name' => $to, 'lat' => 50.95, 'lng' => 6.95],
        'depart_at' => $at($depart), 'arrive_at' => $at($arrive), 'depart_time' => $depart, 'arrive_time' => $arrive,
        'duration_min' => $minutes,
    ];
}

/** A direct run on a stretch, as TransitousAdapter::directRides reads it. */
function choiceRun(string $line, string $depart, string $arrive, bool $cancelled = false): array
{
    return ['line' => $line, 'headsign' => null, 'color' => null, 'cancelled' => $cancelled,
        'depart_at' => "2026-11-13T{$depart}:00+01:00", 'arrive_at' => "2026-11-13T{$arrive}:00+01:00"];
}

/** RideChoices on an engine that answers the stretches, in the order the way rides them, with the runs given. */
function choicesWith(array $byStretch): RideChoices
{
    config(['services.motis.url' => null, 'services.transitous.url' => 'https://transitous.test']);
    $engine = Mockery::mock(TransitousAdapter::class);
    $engine->shouldReceive('withTimeBudget')->andReturnSelf();
    $engine->shouldReceive('directRides')->once()->andReturnUsing(function (array $stretches) use ($byStretch) {
        expect($stretches)->toHaveCount(count($byStretch));

        return array_values($byStretch);
    });
    app()->instance(TransitousAdapter::class, $engine);

    return app(RideChoices::class);
}

test('after a change, another line that makes the same connection is named beside the ride', function () {
    $journey = ['arrive_at' => '2026-11-13T12:25:00+01:00', 'legs' => [
        choiceLeg('tram', '12', 'Köln Merkenich Mitte', 'Köln Ebertplatz', '11:47', '12:10'),
        choiceLeg('walk', null, 'Köln Ebertplatz', 'Köln Ebertplatz', '12:10', '12:13'),
        choiceLeg('tram', '16', 'Köln Ebertplatz', 'Köln Appellhofplatz', '12:16', '12:22'),
        choiceLeg('walk', null, 'Köln Appellhofplatz', '', '12:22', '12:25'),
    ]];
    $choices = choicesWith([
        'köln merkenich mitte|köln ebertplatz' => [choiceRun('12', '11:47', '12:10')],
        'köln ebertplatz|köln appellhofplatz' => [
            choiceRun('16', '12:16', '12:22'),
            choiceRun('18', '12:14', '12:20'),
            choiceRun('5', '12:15', '12:31'),
            choiceRun('18', '12:24', '12:30'),
        ],
    ]);

    $legs = $choices->add([$journey], 3.0, CarbonImmutable::parse('2026-11-13T11:40:00+01:00'))[0]['legs'];

    expect(collect($legs[2]['also'])->pluck('line')->all())->toBe(['18'])
        ->and($legs[2]['also'][0]['depart_time'])->toBe('12:14')
        ->and($legs[2]['change']['spare_min'])->toBe(3)
        ->and($legs[2]['change']['tight'])->toBeFalse()
        ->and($legs[2]['change']['next'])->toBeNull()
        ->and($legs[0])->not->toHaveKey('change');
});

test('a change with a minute to spare is tight, and says the next run and when it gets there', function () {
    $journey = ['arrive_at' => '2026-11-13T12:06:00+01:00', 'legs' => [
        choiceLeg('tram', '9', 'Köln Sülz Weyertal', 'Köln Bf Deutz/Messe', '11:35', '11:52'),
        choiceLeg('walk', null, 'Köln Bf Deutz/Messe', 'Köln Bf Deutz/Messe', '11:52', '11:55'),
        choiceLeg('tram', '4', 'Köln Bf Deutz/Messe', 'Köln Mülheim Wiener Platz', '11:56', '12:05'),
        choiceLeg('walk', null, 'Köln Mülheim Wiener Platz', '', '12:05', '12:06'),
    ]];
    $choices = choicesWith([
        'köln sülz weyertal|köln bf deutz/messe' => [choiceRun('9', '11:35', '11:52')],
        'köln bf deutz/messe|köln mülheim wiener platz' => [
            choiceRun('4', '11:56', '12:05'),
            choiceRun('4', '12:01', '12:10', cancelled: true),
            choiceRun('4', '12:06', '12:15'),
        ],
    ]);

    $change = $choices->add([$journey], 3.0, CarbonImmutable::parse('2026-11-13T11:30:00+01:00'))[0]['legs'][2]['change'];

    expect($change['spare_min'])->toBe(1)
        ->and($change['tight'])->toBeTrue()
        ->and($change['next'])->toMatchArray(['line' => '4', 'depart_time' => '12:06', 'arrive_time' => '12:15'])
        ->and($change['there_time'])->toBe('12:16');
});
